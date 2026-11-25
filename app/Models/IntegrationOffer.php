<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['external_sku', 'name', 'device_model', 'revision'])]
class IntegrationOffer extends Model
{
    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }
}
