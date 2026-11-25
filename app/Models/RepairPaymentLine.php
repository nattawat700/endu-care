<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'branch_id', 'channel', 'label', 'amount_satang', 'tendered_satang',
    'transfer_reference', 'slip_filename', 'slip_type', 'slip_digest',
])]
#[Hidden(['slip_filename', 'slip_type', 'slip_digest'])]
class RepairPaymentLine extends Model
{
    public $timestamps = false;

    public function changeSatang(): int
    {
        return $this->tendered_satang === null ? 0 : $this->tendered_satang - $this->amount_satang;
    }
}
