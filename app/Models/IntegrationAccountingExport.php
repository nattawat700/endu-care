<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['created_by', 'submission_id', 'rows'])]
class IntegrationAccountingExport extends Model
{
    protected function casts(): array
    {
        return ['rows' => 'array'];
    }
}
