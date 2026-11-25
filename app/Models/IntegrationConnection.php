<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['public_id', 'kind', 'name', 'account_ref', 'enabled', 'secret', 'credentials', 'last_received_at'])]
#[Hidden(['secret', 'credentials'])]
class IntegrationConnection extends Model
{
    public const KINDS = ['endu' => 'SKUZA / แค็ตตาล็อก ENDU', 'store' => 'ร้านออนไลน์ / ออเดอร์กลาง', 'shipping' => 'ขนส่ง', 'payment' => 'บัญชีรับเงิน', 'line' => 'LINE OA', 'accounting' => 'บัญชี'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'secret' => 'encrypted', 'credentials' => 'encrypted:array', 'last_received_at' => 'immutable_datetime'];
    }

    public function links(): HasMany
    {
        return $this->hasMany(IntegrationLink::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(IntegrationOffer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(IntegrationOrder::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(IntegrationShipment::class);
    }

    public function accountingExports(): HasMany
    {
        return $this->hasMany(IntegrationAccountingExport::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
