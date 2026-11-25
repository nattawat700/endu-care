<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['created_by', 'kind', 'status', 'report', 'payload'])]
#[Hidden(['payload'])]
class IntegrationImport extends Model
{
    public const KINDS = ['products' => 'สินค้า', 'customers' => 'ลูกค้า', 'opening' => 'สต๊อกตั้งต้น'];

    protected function casts(): array
    {
        return ['report' => 'array', 'payload' => 'encrypted:array'];
    }
}
