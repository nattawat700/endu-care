<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'code', 'qr_filename', 'other_channel_name'])]
class BranchPaymentPoint extends Model
{
    public static function qrDirectory(Branch $branch): string
    {
        return 'payment-qr/'.$branch->id;
    }
}
