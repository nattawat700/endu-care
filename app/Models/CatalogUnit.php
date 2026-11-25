<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'catalog_warehouse_id', 'catalog_location_id', 'serial_number', 'imei', 'available', 'cost_satang'])]
#[Hidden(['cost_satang'])]
class CatalogUnit extends Model
{
    public $timestamps = false;

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(CatalogWarehouse::class, 'catalog_warehouse_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(CatalogLocation::class, 'catalog_location_id');
    }

    protected function casts(): array
    {
        return ['available' => 'boolean', 'cost_satang' => 'integer'];
    }
}
