<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'catalog_product_id', 'barcode', 'catalog_version', 'catalog_unit_id', 'sku', 'name', 'kind', 'unit_name',
    'quantity', 'multiplier', 'unit_satang', 'discount_satang', 'imei', 'serial_number',
])]
class PosSaleLine extends Model
{
    public $timestamps = false;
}
