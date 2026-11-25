<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['recorded_by', 'submission_id', 'kind', 'amount_satang', 'reason'])]
class CashShiftMovement extends Model
{
    public const KINDS = ['top_up' => 'เติมเงินทอน', 'deposit' => 'นำเงินสดฝาก', 'expense' => 'ค่าใช้จ่ายย่อย'];
}
