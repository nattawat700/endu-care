<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'device_name', 'paper'])]
class BranchPrinterProfile extends Model
{
    public const PAPERS = ['a4' => 'A4', 'a5' => 'A5', 'thermal_80' => '80 มม.', 'thermal_58' => '58 มม.'];
}
