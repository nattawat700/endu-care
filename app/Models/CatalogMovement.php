<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Hidden(['unit_cost_satang', 'total_cost_satang'])]
class CatalogMovement extends Model
{
    public const KINDS = ['receive' => 'ใบรับเข้าคลัง', 'issue' => 'ใบเบิกอะไหล่', 'return' => 'ใบคืนอะไหล่', 'sale' => 'ใบตัดสต๊อกขาย', 'sale_return' => 'ใบคืนสต๊อกจากการยกเลิกขาย'];

    protected $guarded = ['id'];

    public function repair(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function integrationOrder(): BelongsTo
    {
        return $this->belongsTo(IntegrationOrder::class);
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(CatalogUnit::class, 'catalog_unit_id');
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'issue_id');
    }

    public function posSale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(self::class, 'issue_id');
    }

    public function reference(): string
    {
        return strtoupper($this->kind).'-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public static function proportionalCost(int $total, int $quantity, int $portion): int
    {
        return intdiv($total, $quantity) * $portion + intdiv(($total % $quantity) * $portion, $quantity);
    }

    public function costForQuantity(int $quantity): ?int
    {
        if ($this->total_cost_satang !== null) {
            return self::proportionalCost((int) $this->total_cost_satang, (int) $this->quantity, $quantity);
        }

        return $this->unit_cost_satang === null ? null : (int) $this->unit_cost_satang * $quantity;
    }
}
