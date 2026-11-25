<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalKind;
use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\RepairJob;
use App\Models\RepairPayment;
use App\Models\RepairRefund;
use App\Support\PaymentProof;
use App\Support\QuotationAmounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepairRefundController extends Controller
{
    public function request(Request $request, Branch $branch, RepairJob $repair, RepairPayment $payment): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        $fields = $request->validate([
            'submission_id' => ['required', 'uuid'], 'line_id' => ['nullable', 'integer', 'min:1'],
            'refund_amount' => ['required', QuotationAmounts::MONEY], 'refund_reason' => ['required', 'string', 'max:400'],
        ]);
        DB::transaction(function () use ($request, $branch, $repair, $payment, $fields): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $current = $repair->payments()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($current->status === 'confirmed', 409);
            $existing = $current->refunds()->where('submission_id', $fields['submission_id'])->first();
            if ($existing) {
                abort_unless((int) $existing->requested_by === $request->user()->id, 409);

                return;
            }
            $line = isset($fields['line_id']) ? $current->lines()->findOrFail($fields['line_id']) : null;
            if ($line === null && $current->lines()->exists()) {
                throw ValidationException::withMessages(['line_id' => 'กรุณาเลือกช่องทางที่ชำระจริงในรายการเดิม']);
            }
            $reserved = (int) $current->refunds()->where('repair_payment_line_id', $line?->id)
                ->whereIn('status', ['pending', 'approved', 'completed'])->sum('amount_satang');
            $amount = QuotationAmounts::hundredths($fields['refund_amount']);
            if ($amount < 1 || $amount > ($line?->amount_satang ?? $current->amount_satang) - $reserved) {
                throw ValidationException::withMessages(['refund_amount' => 'ยอดคืนต้องมากกว่าศูนย์และไม่เกินยอดที่ยังคืนได้ในช่องทางนี้']);
            }
            $rule = $branch->organization()->firstOrFail()->approval_rules[ApprovalKind::Refund->value] ?? null;
            $reviewRole = $rule && $amount > $rule['limit'] ? $rule['review_role'] : null;
            $roles = $reviewRole ? [$reviewRole] : array_map(fn ($role) => $role->value, ApprovalKind::reviewRoles());
            if (! $branch->memberships()->whereIn('role', $roles)->where('user_id', '!=', $request->user()->id)
                ->whereHas('user', fn ($query) => $query->whereNull('suspended_at'))->exists()) {
                throw ValidationException::withMessages(['refund_amount' => 'ยังไม่มีผู้อนุมัติอีกคนที่ตรงตามสิทธิ์ของสาขา']);
            }
            $current->refunds()->create([
                'branch_id' => $branch->id, 'repair_payment_line_id' => $line?->id, 'channel' => $line?->channel ?? $current->channel,
                'amount_satang' => $amount, 'reason' => $fields['refund_reason'], 'requested_by' => $request->user()->id,
                'review_role' => $reviewRole, 'status' => 'pending', 'submission_id' => $fields['submission_id'],
            ]);
        }, 3);

        return back()->with('status', 'ส่งคำขอคืนเงินแล้ว');
    }

    public function review(Request $request, Branch $branch, RepairJob $repair, RepairPayment $payment, RepairRefund $refund): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        abort_unless($refund->canReview($branch, $request->user()), 403);
        $fields = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])], 'refund_review_note' => ['required', 'string', 'max:400'],
        ]);
        DB::transaction(function () use ($branch, $payment, $refund, $request, $fields): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $current = $payment->refunds()->lockForUpdate()->findOrFail($refund->id);
            abort_unless($current->canReview($branch, $request->user()), 403);
            if ($current->status === $fields['decision'] && (int) $current->reviewed_by === $request->user()->id) {
                return;
            }
            abort_unless($current->status === 'pending', 409);
            $current->update([
                'status' => $fields['decision'], 'review_note' => $fields['refund_review_note'],
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
            ]);
        }, 3);

        return back()->with('status', 'บันทึกผลอนุมัติคืนเงินแล้ว');
    }

    public function complete(Request $request, Branch $branch, RepairJob $repair, RepairPayment $payment, RepairRefund $refund): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        $request->validate(['refund_proof' => ['required', ...PaymentProof::IMAGE_RULES], 'paid' => ['accepted']]);
        $proof = PaymentProof::store($request->file('refund_proof'), RepairPayment::slipDirectory($branch), 'refund_proof');
        $saved = false;
        try {
            $saved = DB::transaction(function () use ($branch, $repair, $payment, $refund, $request, $proof): bool {
                Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
                $job = $branch->repairs()->lockForUpdate()->findOrFail($repair->id);
                $current = $payment->refunds()->lockForUpdate()->findOrFail($refund->id);
                if ($current->status === 'completed') {
                    abort_unless((int) $current->paid_by === $request->user()->id, 409);

                    return false;
                }
                abort_unless($current->status === 'approved', 409);
                if ($job->payments()->where('status', 'pending')->exists()) {
                    throw ValidationException::withMessages(['refund_proof' => 'กรุณาตรวจรายการรับเงินที่ค้างก่อนคืนเงิน']);
                }
                $shift = CashShift::active($branch, $request->user());
                if (! $shift || ($current->channel === 'cash' && $shift->totals()['cash_expected'] < $current->amount_satang)) {
                    throw ValidationException::withMessages(['refund_proof' => 'กรุณาเปิดกะและตรวจว่าเงินสดในลิ้นชักเพียงพอ']);
                }
                $current->update([
                    'cash_shift_id' => $shift->id, 'paid_by' => $request->user()->id, 'paid_at' => now(),
                    'proof_filename' => $proof['filename'], 'proof_type' => $proof['type'], 'status' => 'completed',
                ]);

                return true;
            }, 3);
        } finally {
            if (! $saved) {
                Storage::disk('local')->delete($proof['path']);
            }
        }

        return back()->with('status', 'บันทึกยอดคืนเงินจริงแล้ว');
    }

    public function proof(Branch $branch, RepairJob $repair, RepairPayment $payment, RepairRefund $refund): StreamedResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);

        return PaymentProof::response(RepairPayment::slipDirectory($branch), $refund->proof_filename, $refund->proof_type);
    }
}
