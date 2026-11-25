<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'kind'])]
class CatalogWarehouse extends Model
{
    public const KINDS = ['sale' => 'คลังขาย', 'inspection' => 'คลังพักตรวจ'];

    protected $attributes = ['kind' => 'sale'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(CatalogLocation::class);
    }
}
