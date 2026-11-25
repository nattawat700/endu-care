<?php

namespace App\Http\Controllers;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\RepairJob;
use App\Models\RepairPayment;
use App\Models\RepairPaymentLine;
use App\Models\RepairQuotation;
use App\Support\DocumentPdf;
use App\Support\DocumentPrintHistory;
use App\Support\PaymentProof;
use App\Support\QuotationAmounts;
use App\Support\RepairBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepairPaymentController extends Controller
{
    public function register(Request $request, Branch $branch): Response|RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        $fields = $request->validate(['repair_reference' => ['nullable', 'string', 'regex:/\A(?:REP-)?[0-9]{1,18}\z/i']]);
        if (isset($fields['repair_reference'])) {
            $repair = $branch->repairs()->find((int) preg_replace('/\AREP-/i', '', $fields['repair_reference']));
            if ($repair === null) {
                return redirect()->route('payment-documents.index', $branch)
                    ->withInput()->withErrors(['repair_reference' => 'ไม่พบใบงานในสาขานี้']);
            }

            return redirect()->route('repairs.payments.index', [$branch, $repair]);
        }

        $payments = $branch->repairPayments()->with(['repairJob:id', 'lines'])->latest('id')->cursorPaginate(25);

        return response()->view('payments.register', compact('branch', 'payments'))->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, Branch $branch, RepairJob $repair): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        $billing = RepairBilling::summary($repair);
        $activeShift = CashShift::active($branch, $request->user())?->load(['point', 'counter']);
        $payments = $repair->payments()->with('lines')->latest('id')->cursorPaginate(25);
        $submissionId = (string) Str::uuid();

        return response()->view('payments.index', compact('branch', 'repair', 'billing', 'payments', 'submissionId', 'activeShift'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Branch $branch, RepairJob $repair): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        if (! $request->has('lines') && in_array($request->input('channel'), ['cash', 'transfer'], true)) {
            $channel = $request->input('channel');
            $request->merge(['lines' => [$channel => $request->input('amount')]]);
        }
        $fields = $request->validate([
            'submission_id' => ['required', 'uuid'],
            'billing_version' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'document_type' => ['required', Rule::in(array_keys(RepairPayment::TYPES))],
            'lines' => ['required', 'array:cash,transfer,qr,other'],
            'lines.*' => ['nullable', QuotationAmounts::MONEY],
            'tendered' => ['nullable', QuotationAmounts::MONEY],
            'confirmed' => ['accepted'],
            'note' => ['nullable', 'string', 'max:500'],
            'transfer_reference' => ['nullable', 'string', 'max:80'],
            'qr_reference' => ['nullable', 'string', 'max:80'],
            'slip' => ['nullable', ...PaymentProof::IMAGE_RULES],
            'qr_slip' => ['nullable', ...PaymentProof::IMAGE_RULES],
        ], ['confirmed.accepted' => 'กรุณายืนยันยอดที่บันทึก']);
        $lines = array_filter(array_map(fn ($value) => QuotationAmounts::hundredths($value ?? 0), $fields['lines']));
        $total = array_sum($lines);
        $tendered = isset($fields['tendered']) ? QuotationAmounts::hundredths($fields['tendered']) : ($lines['cash'] ?? null);
        $directory = RepairPayment::slipDirectory($branch);
        $proofs = [];
        $created = false;
        try {
            foreach (['transfer' => ['slip', 'transfer_reference'], 'qr' => ['qr_slip', 'qr_reference']] as $channel => [$fileKey, $referenceKey]) {
                if (isset($lines[$channel])) {
                    $file = $request->file($fileKey);
                    if ($file === null || ! isset($fields[$referenceKey])) {
                        throw ValidationException::withMessages([$file === null ? $fileKey : $referenceKey => 'กรุณาระบุเลขอ้างอิงและแนบสลิปของช่องทางนี้']);
                    }
                    $proofs[$channel] = PaymentProof::store($file, $directory, $fileKey) + ['reference' => Str::upper($fields[$referenceKey])];
                }
            }
            [$payment, $created] = DB::transaction(function () use ($branch, $repair, $request, $fields, $lines, $total, $tendered, $proofs): array {
                Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
                $job = $branch->repairs()->lockForUpdate()->findOrFail($repair->id);
                $existing = $job->payments()->where('submission_id', $fields['submission_id'])->first();
                if ($existing !== null) {
                    abort_unless((int) $existing->received_by === $request->user()->id, 409);

                    return [$existing, false];
                }

                $shift = CashShift::active($branch, $request->user());
                if ($shift === null) {
                    throw ValidationException::withMessages(['shift' => 'กรุณาเปิดกะก่อนรับเงิน']);
                }
                $shift->load('point');
                $billing = RepairBilling::summary($job);
                if (! $billing['canReceive'] || $billing['version'] !== $fields['billing_version'] || $billing['pending'] > 0) {
                    throw ValidationException::withMessages(['billing_version' => 'ยอดหรือรายการตรวจโอนเปลี่ยนแล้ว กรุณาเปิดหน้ายอดชำระล่าสุด']);
                }
                if ($total < 1 || $total > $billing['remaining']) {
                    throw ValidationException::withMessages(['amount' => 'ยอดรับจริงต้องมากกว่า 0 และไม่เกินยอดคงเหลือ']);
                }
                if (($lines['cash'] ?? 0) > ($tendered ?? 0) || ($tendered > 0 && ! isset($lines['cash']))) {
                    throw ValidationException::withMessages(['tendered' => 'เงินสดที่ลูกค้ายื่นต้องไม่น้อยกว่ายอดส่วนเงินสด']);
                }
                if ($tendered !== null && isset($lines['cash']) && $tendered - $lines['cash'] > $shift->totals()['cash_expected']) {
                    throw ValidationException::withMessages(['tendered' => 'เงินทอนในลิ้นชักไม่เพียงพอ']);
                }
                if ($fields['document_type'] === 'deposit' && $total === $billing['remaining']) {
                    throw ValidationException::withMessages(['document_type' => 'ยอดนี้ชำระครบแล้ว กรุณาเลือกใบเสร็จ']);
                }
                if ($fields['document_type'] === 'cash_receipt' && array_keys($lines) !== ['cash']) {
                    throw ValidationException::withMessages(['document_type' => 'ใบเสร็จเงินสดใช้กับรายการเงินสดเท่านั้น']);
                }
                if (isset($lines['qr']) && ! $shift->point->qr_filename) {
                    throw ValidationException::withMessages(['lines.qr' => 'จุดรับเงินนี้ยังไม่ได้ตั้ง QR']);
                }
                if (isset($lines['other']) && ! $shift->point->other_channel_name) {
                    throw ValidationException::withMessages(['lines.other' => 'จุดรับเงินนี้ยังไม่ได้เปิดช่องทางเพิ่มเติม']);
                }
                $this->validateProofs($branch, $proofs);
                $payment = $job->payments()->create([
                    'branch_id' => $branch->id, 'repair_quotation_id' => $billing['quotation']?->id,
                    'received_by' => $request->user()->id, 'submission_id' => $fields['submission_id'], 'cash_shift_id' => $shift->id,
                    'channel' => count($lines) === 1 ? array_key_first($lines) : 'mixed',
                    'document_type' => $fields['document_type'], 'status' => $proofs === [] ? 'confirmed' : 'pending',
                    'job_total_satang' => $billing['total'], 'payable_satang' => $billing['payable'],
                    'paid_before_satang' => $billing['paid'], 'amount_satang' => $total,
                    'document' => $this->document($branch, $job, $billing['quotation'], $request->user()->name, $fields['note'] ?? null),
                ]);
                foreach ($lines as $channel => $amount) {
                    $proof = $proofs[$channel] ?? [];
                    $payment->lines()->create([
                        'branch_id' => $branch->id, 'channel' => $channel,
                        'label' => $channel === 'other' ? $shift->point->other_channel_name : RepairPayment::CHANNELS[$channel],
                        'amount_satang' => $amount, 'tendered_satang' => $channel === 'cash' ? $tendered : null,
                        'transfer_reference' => $proof['reference'] ?? null, 'slip_filename' => $proof['filename'] ?? null,
                        'slip_type' => $proof['type'] ?? null, 'slip_digest' => $proof['digest'] ?? null,
                    ]);
                }

                return [$payment, true];
            }, 3);
        } finally {
            if (! $created) {
                Storage::disk('local')->delete(array_column($proofs, 'path'));
            }
        }

        return redirect()->route('repairs.payments.show', [$branch, $repair, $payment])->with('status',
            $payment->status === 'pending' ? 'บันทึกรายการแล้ว รอตรวจยอดโอน' : 'บันทึกรับเงินแล้ว');
    }

    public function verify(Request $request, Branch $branch, RepairJob $repair, RepairPayment $payment): RedirectResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::VerifyPayments]);
        $fields = $request->validate([
            'decision' => ['required', Rule::in(['confirmed', 'rejected'])],
            'review_note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:400'],
            'returned_cash' => ['sometimes', 'accepted'],
            'returned_other' => ['sometimes', 'accepted'],
        ]);
        DB::transaction(function () use ($request, $branch, $repair, $payment, $fields): void {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $job = $branch->repairs()->lockForUpdate()->findOrFail($repair->id);
            $current = $job->payments()->lockForUpdate()->findOrFail($payment->id);
            if ($current->status === $fields['decision']) {
                return;
            }
            abort_unless($current->status === 'pending' && $current->shift && ! $current->shift->closed_at, 409);
            $billing = RepairBilling::summary($job);
            if ($fields['decision'] === 'confirmed' && ($billing['payable'] !== $current->payable_satang
                || $billing['quotation']?->id !== $current->repair_quotation_id || $billing['paid'] !== $current->paid_before_satang)) {
                throw ValidationException::withMessages(['decision' => 'ยอดใบงานเปลี่ยนแล้ว กรุณาตรวจรายการก่อนรับชำระใหม่']);
            }
            if ($fields['decision'] === 'rejected') {
                $cash = (int) $current->lines()->where('channel', 'cash')->sum('amount_satang');
                if ($cash > 0 && (! $request->boolean('returned_cash') || $current->shift->totals()['cash_expected'] < $cash)) {
                    throw ValidationException::withMessages(['returned_cash' => 'ยืนยันการคืนเงินสดส่วนที่รับจริงและตรวจยอดเงินในลิ้นชักก่อนปฏิเสธ']);
                }
                if ($current->lines()->where('channel', 'other')->exists() && ! $request->boolean('returned_other')) {
                    throw ValidationException::withMessages(['returned_other' => 'ยืนยันการคืนเงินที่รับผ่านช่องทางเพิ่มเติมก่อนปฏิเสธ']);
                }
            }
            $current->update(['status' => $fields['decision'], 'review_note' => $fields['review_note'] ?? null,
                'verified_by' => $request->user()->id, 'verified_at' => now()]);
        }, 3);

        return back()->with('status', 'บันทึกผลตรวจยอดแล้ว');
    }

    private function validateProofs(Branch $branch, array $proofs): void
    {
        foreach (['reference', 'digest'] as $key) {
            if (count(array_unique(array_column($proofs, $key))) !== count($proofs)) {
                throw ValidationException::withMessages(['transfer_reference' => 'เลขอ้างอิงและสลิปของแต่ละช่องทางต้องแตกต่างกัน']);
            }
        }
        foreach ($proofs as $proof) {
            $match = fn ($query) => $query->where('transfer_reference', $proof['reference'])->orWhere('slip_digest', $proof['digest']);
            if ($branch->repairPayments()->where($match)->exists() || RepairPaymentLine::where('branch_id', $branch->id)->where($match)->exists()) {
                throw ValidationException::withMessages(['transfer_reference' => 'เลขอ้างอิงหรือสลิปนี้เคยบันทึกในสาขาแล้ว']);
            }
        }
    }

    public function show(Branch $branch, RepairJob $repair, RepairPayment $payment): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        $payment->load(['lines', 'verifier:id,name', 'shift.counter']);
        $refunds = $payment->refunds()->with('reviewer:id,name')->latest('id')->simplePaginate(20);
        $submissionId = (string) Str::uuid();
        $activeShift = CashShift::active($branch, auth()->user());

        return $this->renderDocument('payments.document', $branch, $repair, $payment, extra: compact('refunds', 'submissionId', 'activeShift'));
    }

    public function print(Request $request, Branch $branch, RepairJob $repair, RepairPayment $payment): Response
    {
        return $this->renderDocument('payments.print', $branch, $repair, $payment, $request->query('format') === 'pdf');
    }

    private function renderDocument(string $view, Branch $branch, RepairJob $repair, RepairPayment $payment, bool $pdf = false, array $extra = []): Response
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);

        abort_if($view === 'payments.print' && $payment->status !== 'confirmed', 409);
        $payment->loadMissing('lines');
        $data = [
            'branch' => $branch, 'repair' => $repair, 'payment' => $payment,
            'canViewPrivateCustomerDetails' => Gate::allows('permission', [$branch, BranchPermission::PrivateCustomerDetails]),
        ] + $extra;

        if ($pdf) {
            $pdf = app(DocumentPdf::class)->render($view, $data, $payment->reference());

            return DocumentPrintHistory::record($pdf, $branch, 'repair_payment', $payment->id, $payment->reference());
        }

        return response()->view($view, $data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function depositProof(Branch $branch, RepairJob $repair, RepairPayment $payment): Response
    {
        $repair->setRelation('branch', $branch);
        Gate::authorize('viewDeposits', $repair);
        abort_unless($payment->document_type === 'deposit' && $payment->status === 'confirmed', 404);
        $refunded = (int) $payment->refunds()->where('status', 'completed')->sum('amount_satang');

        return response()->view('repairs.deposit-proof', compact('branch', 'repair', 'payment', 'refunded'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function slip(Request $request, Branch $branch, RepairJob $repair, RepairPayment $payment): StreamedResponse
    {
        Gate::authorize('permission', [$branch, BranchPermission::ManagePaymentDocuments]);
        $fields = $request->validate(['line' => ['nullable', 'integer', 'min:1']]);
        $source = isset($fields['line']) ? $payment->lines()->findOrFail($fields['line']) : $payment;

        return PaymentProof::response(RepairPayment::slipDirectory($branch), $source->slip_filename, $source->slip_type);
    }

    private function document(Branch $branch, RepairJob $job, ?RepairQuotation $quotation, string $receiver, ?string $note): array
    {
        $buyer = array_intersect_key($quotation?->document_details ?? [], array_flip([
            'buyer_phone', 'buyer_address', 'buyer_tax_id', 'buyer_email', 'buyer_office',
        ]));
        $items = $quotation?->items ?? [[
            'description' => 'งานซ่อม '.$job->device, 'type' => 'labor', 'quantity' => 1, 'unit' => 'งาน',
            'unit_satang' => $job->fixed_price_satang, 'total_satang' => $job->fixed_price_satang,
        ]];

        return [
            'seller' => $branch->documentSeller(),
            'buyer_name' => $quotation?->customer_name ?? $job->customer_name,
            'buyer' => $buyer + ['buyer_phone' => $job->customer_phone, 'buyer_address' => $job->customer_address],
            'device' => $quotation?->device ?? $job->device, 'receiver' => $receiver, 'note' => $note,
            'quotation_reference' => $quotation?->reference(), 'items' => $items,
            'amount_breakdown' => $quotation?->amount_breakdown,
        ];
    }
}
