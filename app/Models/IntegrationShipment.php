<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tracking_number', 'integration_order_id', 'status', 'occurred_at'])]
class IntegrationShipment extends Model
{
    public const STATUSES = ['pending' => 'รอขนส่ง', 'picked_up' => 'รับพัสดุแล้ว', 'in_transit' => 'ระหว่างขนส่ง', 'delivered' => 'ส่งสำเร็จ', 'exception' => 'จัดส่งมีปัญหา', 'returned' => 'ส่งคืน'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(IntegrationOrder::class, 'integration_order_id');
    }
}
