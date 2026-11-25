<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_counter_id', 'customer_id', 'created_by', 'salesperson_id', 'paid_by', 'voided_by', 'approval_id', 'submission_id',
    'status', 'price_group', 'version', 'subtotal_satang', 'line_discount_satang', 'document_discount_satang',
    'total_satang', 'seller', 'buyer', 'paid_at', 'voided_at', 'void_reason',
])]
class PosSale extends Model
{
    public const STATUSES = ['draft' => 'พักบิล', 'paid' => 'ชำระแล้ว', 'voided' => 'ยกเลิกแล้ว'];

    public function counter(): BelongsTo
    {
        return $this->belongsTo(BranchCounter::class, 'branch_counter_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(OrganizationApprovalRequest::class, 'approval_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosSaleLine::class)->orderBy('id');
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(PosTender::class);
    }

    public function creditChanges(): HasMany
    {
        return $this->hasMany(PosCreditChange::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CatalogMovement::class);
    }

    public function reference(): string
    {
        return sprintf('POS-%08d', $this->id);
    }

    public function discountTotal(): int
    {
        return $this->line_discount_satang + $this->document_discount_satang;
    }

    protected function casts(): array
    {
        return ['buyer' => 'array', 'seller' => 'array', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];
    }
}
