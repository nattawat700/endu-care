<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'code', 'payment_point_id', 'printer_profile_id'])]
class BranchCounter extends Model
{
    public function paymentPoint(): BelongsTo
    {
        return $this->belongsTo(BranchPaymentPoint::class);
    }

    public function printerProfile(): BelongsTo
    {
        return $this->belongsTo(BranchPrinterProfile::class);
    }
}
