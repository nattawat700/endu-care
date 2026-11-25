<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['branch_id', 'catalog_warehouse_id', 'quantity'])]
#[Hidden(['stock_value_satang'])]
class CatalogBalance extends Model
{
    public $timestamps = false;

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(CatalogWarehouse::class, 'catalog_warehouse_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(CatalogLocationBalance::class);
    }
}
