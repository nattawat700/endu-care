<?php

namespace App\Models;

use App\Enums\QuotationDecision;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'revision', 'submission_id', 'organization_name', 'branch_name', 'customer_name', 'device', 'issued_by',
    'issuer_name', 'items', 'total_satang', 'terms', 'requires_internal_approval', 'repair_assessment_id', 'revision_reason', 'seller_profile', 'valid_until', 'document_details', 'amount_breakdown',
])]
class RepairQuotation extends Model
{
    public const ITEM_TYPES = ['labor' => 'ค่าแรง', 'part' => 'อะไหล่', 'product' => 'สินค้า'];

    public const APPROVAL_CHANNELS = [
        'counter' => 'หน้าร้าน',
        'phone' => 'โทรศัพท์',
        'line' => 'LINE',
        'other' => 'ช่องทางอื่น',
    ];

    public function repairJob(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function customerApprovalRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_requested_by');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RepairAssessment::class, 'repair_assessment_id');
    }

    public function internalRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_requested_by');
    }

    public function internalReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_decided_by');
    }

    public function scopeLatestRevision(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($newer) => $newer->selectRaw('1')
            ->from('repair_quotations as newer')
            ->whereColumn('newer.repair_job_id', 'repair_quotations.repair_job_id')
            ->whereColumn('newer.revision', '>', 'repair_quotations.revision'));
    }

    public function signatureDirectory(Branch $branch): string
    {
        return 'quotation-signatures/'.$branch->organization_id.'/'.$this->id;
    }

    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->valid_until !== null
            && ($at ?? now())->copy()->timezone('Asia/Bangkok')->toDateString() > $this->valid_until->toDateString();
    }

    public function canRelease(): bool
    {
        return ! $this->requires_internal_approval || $this->internal_decision === QuotationDecision::Approved;
    }

    public function internalApprovalLabel(bool $isLatest = true): string
    {
        return match (true) {
            ! $this->requires_internal_approval => 'ออกก่อนเริ่มขั้นตอนอนุมัติ',
            $this->internal_decision !== null => $this->internal_decision->label(),
            ! $isLatest => 'แทนที่ด้วยฉบับใหม่',
            $this->internal_requested_at !== null => 'รอผู้จัดการอนุมัติ',
            default => 'ร่าง · ยังไม่ส่งขออนุมัติ',
        };
    }

    public function reference(): string
    {
        return 'QT-'.str_pad((string) $this->repair_job_id, 6, '0', STR_PAD_LEFT).'-'.str_pad((string) $this->revision, 2, '0', STR_PAD_LEFT);
    }

    public static function formatBaht(int $satang, string $thousandsSeparator = ','): string
    {
        return number_format(intdiv($satang, 100), 0, '.', $thousandsSeparator).'.'.str_pad((string) ($satang % 100), 2, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'seller_profile' => 'array',
            'document_details' => 'array',
            'amount_breakdown' => 'array',
            'valid_until' => 'immutable_date',
            'revision' => 'integer',
            'total_satang' => 'integer',
            'repair_assessment_id' => 'integer',
            'approved_at' => 'datetime',
            'customer_requested_at' => 'datetime',
            'customer_confirmed_at' => 'datetime',
            'requires_internal_approval' => 'boolean',
            'internal_requested_at' => 'datetime',
            'internal_decided_at' => 'datetime',
            'internal_decision' => QuotationDecision::class,
        ];
    }
}
