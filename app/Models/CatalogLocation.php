<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'name'])]
class CatalogLocation extends Model
{
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(CatalogWarehouse::class, 'catalog_warehouse_id');
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }
}
