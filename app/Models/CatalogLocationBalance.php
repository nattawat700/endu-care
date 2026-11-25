<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['catalog_location_id', 'quantity'])]
class CatalogLocationBalance extends Model
{
    public $timestamps = false;

    public function location(): BelongsTo
    {
        return $this->belongsTo(CatalogLocation::class, 'catalog_location_id');
    }
}
