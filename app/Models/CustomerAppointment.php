<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'responsible_user_id', 'submission_id', 'kind', 'status', 'starts_at', 'note', 'version'])]
class CustomerAppointment extends Model
{
    public const KINDS = ['receive' => 'นัดรับเครื่อง', 'repair' => 'นัดซ่อม'];

    public const STATUSES = ['scheduled' => 'นัดหมายแล้ว', 'completed' => 'เข้ารับบริการแล้ว', 'cancelled' => 'ยกเลิก'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
