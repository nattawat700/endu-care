<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['branch_id', 'code', 'unit', 'multiplier'])]
class CatalogBarcode extends Model
{
    public $timestamps = false;
}
