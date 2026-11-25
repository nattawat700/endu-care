<?php

namespace App\Observers;

use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\CatalogMovement;
use App\Models\IssuedDocument;
use App\Models\PosCreditChange;
use App\Models\PosSale;
use App\Models\PosSaleLine;
use App\Models\PosTender;
use App\Models\RepairClosure;
use App\Models\RepairPayment;
use App\Models\RepairPaymentLine;
use App\Models\RepairQuotation;
use App\Models\RepairRefund;
use App\Models\ServiceClaim;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class IssuedRecordObserver
{
    public const MODELS = [
        RepairQuotation::class, RepairPayment::class, RepairPaymentLine::class, RepairRefund::class,
        CashShift::class, CashShiftMovement::class, CatalogMovement::class,
        PosSale::class, PosSaleLine::class, PosTender::class, PosCreditChange::class,
        RepairClosure::class, IssuedDocument::class, ServiceClaim::class,
    ];

    public function creating(Model $record): void
    {
        if ($record instanceof PosSaleLine && ! $this->belongsToDraft($record->pos_sale_id)) {
            $this->reject();
        }
    }

    public function updating(Model $record): void
    {
        if ($record instanceof PosSaleLine && ! $record->isDirty('pos_sale_id') && $this->belongsToDraft($record->pos_sale_id)) {
            return;
        }

        $status = $record->getRawOriginal('status');
        $allowed = match (true) {
            $record instanceof RepairQuotation => $this->quotationChanges($record),
            $record instanceof RepairPayment && $status === 'pending' && in_array($record->status, ['confirmed', 'rejected'], true) => ['status', 'verified_by', 'verified_at', 'review_note'],
            $record instanceof RepairRefund && $status === 'pending' && in_array($record->status, ['approved', 'rejected'], true) => ['status', 'reviewed_by', 'reviewed_at', 'review_note'],
            $record instanceof RepairRefund && $status === 'approved' && $record->status === 'completed' => ['status', 'cash_shift_id', 'paid_by', 'paid_at', 'proof_filename', 'proof_type'],
            $record instanceof CashShift && $record->getRawOriginal('closed_at') === null && $record->closed_at !== null => ['active_counter', 'active_operator', 'closed_by', 'closed_at', 'closing'],
            $record instanceof ServiceClaim && $status === 'received' && in_array($record->status, ['approved', 'rejected'], true) => ['status', 'reviewed_by', 'reviewed_at', 'review'],
            $record instanceof PosSale => $this->saleChanges($record, $status),
            default => [],
        };

        if (array_diff(array_keys($record->getDirty()), $allowed) !== []) {
            $this->reject();
        }
    }

    public function deleting(Model $record): void
    {
        if ($record instanceof PosSaleLine && $this->belongsToDraft($record->getRawOriginal('pos_sale_id'))) {
            return;
        }

        $this->reject();
    }

    private function quotationChanges(RepairQuotation $quote): array
    {
        if ($quote->getRawOriginal('voided_at') !== null) {
            return [];
        }
        if ($quote->voided_at !== null) {
            return ['voided_at', 'voided_by', 'void_reason'];
        }

        return [
            ...($quote->getRawOriginal('internal_requested_at') === null ? ['internal_requested_at', 'internal_requested_by', 'internal_request_reason'] : []),
            ...($quote->getRawOriginal('internal_decision') === null ? ['internal_decision', 'internal_decided_at', 'internal_decided_by', 'internal_decision_reason', 'signature_filename'] : []),
            ...($quote->getRawOriginal('customer_requested_at') === null ? ['customer_requested_at', 'customer_requested_by', 'customer_request_channel'] : []),
            ...($quote->getRawOriginal('approved_at') === null ? ['approved_at', 'approved_by', 'customer_confirmed_at', 'customer_approver_name', 'approval_channel', 'approval_note'] : []),
        ];
    }

    private function saleChanges(PosSale $sale, ?string $original): array
    {
        return match ([$original, $sale->status]) {
            ['draft', 'draft'] => ['salesperson_id', 'approval_id', 'version', 'customer_id', 'buyer', 'price_group',
                'subtotal_satang', 'line_discount_satang', 'document_discount_satang', 'total_satang'],
            ['draft', 'paid'] => ['status', 'seller', 'paid_at', 'paid_by'],
            ['draft', 'voided'], ['paid', 'voided'] => ['status', 'voided_at', 'voided_by', 'void_reason'],
            ['paid', 'paid'] => ['salesperson_id', 'version'],
            default => [],
        };
    }

    private function belongsToDraft(int $saleId): bool
    {
        return PosSale::whereKey($saleId)->where('status', 'draft')->exists();
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['document' => 'เอกสารต้นทางแก้ไขหรือลบไม่ได้ กรุณาใช้รายการยกเลิก คืนเงิน หรือรายการปรับที่อ้างอิงต้นทาง']);
    }
}
