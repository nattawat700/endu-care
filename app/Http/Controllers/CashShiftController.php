<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\BranchPaymentPoint;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Support\PaymentProof;
use App\Support\QuotationAmounts;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashShiftController extends Controller
{
    public function index(Request $request, Branch $branch): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        $activeShift = CashShift::active($branch, $request->user());
        $counters = $branch->counters()->orderBy('name')->get();
        $shifts = $branch->cashShifts()->with(['counter:id,name', 'operator:id,name'])
            ->when($request->user()->cannot('permission', [$branch, BranchPermission::VerifyPayments]), fn ($query) => $query->where('opened_by', $request->user()->id))
            ->latest('id')->cursorPaginate(25);
        $pendingTransfers = $request->user()->can('permission', [$branch, BranchPermission::VerifyPayments])
            ? $branch->repairPayments()->where('status', 'pending')->with('repairJob:id')->oldest('id')->simplePaginate(15, pageName: 'pending_transfers')
            : null;
        $pendingRefunds = $branch->repairPayments()->whereHas('refunds', fn ($query) => $query->whereIn('status', ['pending', 'approved']))
            ->with('repairJob:id')->latest('id')->simplePaginate(15, pageName: 'pending_refunds');
        $submissionId = (string) Str::uuid();

        return response()->view('payments.shifts', compact('branch', 'activeShift', 'counters', 'shifts', 'pendingTransfers', 'pendingRefunds', 'submissionId'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        $fields = $request->validate([
            'counter_id' => ['required', 'integer', 'min:1'], 'opening_cash' => ['required', QuotationAmounts::MONEY],
            'submission_id' => ['required', 'uuid'],
        ]);
        $shift = DB::transaction(function () use ($branch, $request, $fields): CashShift {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $operator = $request->user();
            $prior = $branch->cashShifts()->where('submission_id', $fields['submission_id'])->first();
            if ($prior !== null) {
                abort_unless((int) $prior->opened_by === $operator->id, 409);

                return $prior;
            }
            $counter = $branch->counters()->lockForUpdate()->findOrFail($fields['counter_id']);
            if (CashShift::active($branch, $operator) || $branch->cashShifts()->where('active_counter', $counter->id)->exists()) {
                throw ValidationException::withMessages(['counter_id' => 'คุณหรือเคาน์เตอร์นี้มีกะที่เปิดอยู่แล้ว']);
            }

            return $branch->cashShifts()->create([
                'branch_counter_id' => $counter->id, 'branch_payment_point_id' => $counter->payment_point_id,
                'opened_by' => $operator->id, 'active_operator' => $operator->id, 'active_counter' => $counter->id,
                'opening_satang' => QuotationAmounts::hundredths($fields['opening_cash']), 'submission_id' => $fields['submission_id'],
            ]);
        }, 3);

        return redirect()->route('cash-shifts.show', [$branch, $shift])->with('status', 'เปิดกะแล้ว');
    }

    public function show(Request $request, Branch $branch, CashShift $cashShift): Response
    {
        $this->authorizeShift($request, $branch, $cashShift);
        $shift = $cashShift->load(['counter', 'operator', 'point']);
        $totals = $shift->closing ?? $shift->totals();
        $movements = $shift->movements()->latest('id')->cursorPaginate(25);
        $posTenders = $shift->posTenders()->with('sale:id')->latest('id')->cursorPaginate(15, cursorName: 'pos_tenders');
        $pending = $shift->payments()->where('status', 'pending')->count();
        $submissionId = (string) Str::uuid();

        return response()->view('payments.shift', compact('branch', 'shift', 'totals', 'movements', 'posTenders', 'pending', 'submissionId'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function move(Request $request, Branch $branch, CashShift $cashShift): RedirectResponse
    {
        $this->authorizeShift($request, $branch, $cashShift, operatorOnly: true);
        $fields = $request->validate([
            'kind' => ['required', Rule::in(array_keys(CashShiftMovement::KINDS))],
            'amount' => ['required', QuotationAmounts::MONEY], 'reason' => ['required', 'string', 'max:400'],
            'submission_id' => ['required', 'uuid'],
        ]);
        $this->locked($branch, $cashShift, function (CashShift $shift) use ($fields, $request): void {
            if ($shift->movements()->where('submission_id', $fields['submission_id'])->exists()) {
                return;
            }
            abort_if($shift->closed_at, 409);
            $amount = QuotationAmounts::hundredths($fields['amount']);
            if ($amount < 1 || ($fields['kind'] !== 'top_up' && $amount > $shift->totals()['cash_expected'])) {
                throw ValidationException::withMessages(['amount' => 'ยอดต้องมากกว่าศูนย์และเงินออกต้องไม่เกินยอดในลิ้นชัก']);
            }
            $shift->movements()->create([
                'kind' => $fields['kind'], 'reason' => $fields['reason'], 'amount_satang' => $amount,
                'recorded_by' => $request->user()->id, 'submission_id' => $fields['submission_id'],
            ]);
        });

        return back()->with('status', 'บันทึกเงินเข้า–ออกลิ้นชักแล้ว');
    }

    public function close(Request $request, Branch $branch, CashShift $cashShift): RedirectResponse
    {
        $this->authorizeShift($request, $branch, $cashShift);
        $fields = $request->validate([
            'counted_cash' => ['required', QuotationAmounts::MONEY], 'counted_transfer' => ['required', 'regex:/\A-?(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?\z/'],
            'close_reason' => ['nullable', 'string', 'max:400'],
        ]);
        $this->locked($branch, $cashShift, function (CashShift $shift) use ($fields, $request): void {
            if ($shift->closed_at !== null) {
                return;
            }
            if ($shift->payments()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['close_reason' => 'กรุณาให้ผู้มีสิทธิ์ตรวจยอดโอนที่ค้างก่อนปิดกะ']);
            }
            $totals = $shift->totals();
            $countedCash = QuotationAmounts::hundredths($fields['counted_cash']);
            $rawTransfer = (string) $fields['counted_transfer'];
            $countedTransfer = QuotationAmounts::hundredths(ltrim($rawTransfer, '-')) * (str_starts_with($rawTransfer, '-') ? -1 : 1);
            $cashDifference = $countedCash - $totals['cash_expected'];
            $transferDifference = $countedTransfer - $totals['transfer_expected'];
            if (($cashDifference !== 0 || $transferDifference !== 0) && empty($fields['close_reason'])) {
                throw ValidationException::withMessages(['close_reason' => 'กรุณาระบุเหตุผลที่ยอดจริงต่างจากยอดระบบ']);
            }
            $shift->update([
                'active_counter' => null, 'active_operator' => null, 'closed_by' => $request->user()->id, 'closed_at' => now(),
                'closing' => $totals + [
                    'counted_cash' => $countedCash, 'counted_transfer' => $countedTransfer,
                    'cash_difference' => $cashDifference, 'transfer_difference' => $transferDifference, 'reason' => $fields['close_reason'] ?? null,
                ],
            ]);
        });

        return back()->with('status', 'ปิดกะและบันทึกยอดกระทบแล้ว');
    }

    public function qr(Branch $branch, BranchPaymentPoint $paymentPoint): StreamedResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);

        return PaymentProof::response(BranchPaymentPoint::qrDirectory($branch), $paymentPoint->qr_filename);
    }

    private function authorizeShift(Request $request, Branch $branch, CashShift $shift, bool $operatorOnly = false): void
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        abort_unless((int) $shift->opened_by === $request->user()->id
            || (! $operatorOnly && $request->user()->can('permission', [$branch, BranchPermission::VerifyPayments])), 403);
    }

    private function locked(Branch $branch, CashShift $shift, Closure $operation): void
    {
        DB::transaction(function () use ($branch, $shift, $operation): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $operation($branch->cashShifts()->lockForUpdate()->findOrFail($shift->id));
        }, 3);
    }
}
