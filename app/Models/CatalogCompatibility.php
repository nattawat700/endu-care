<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['device_model'])]
class CatalogCompatibility extends Model
{
    public $timestamps = false;
}
