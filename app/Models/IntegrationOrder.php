<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reference', 'status', 'revision', 'items', 'confirmed_by', 'confirmed_at'])]
class IntegrationOrder extends Model
{
    protected function casts(): array
    {
        return ['revision' => 'integer', 'items' => 'array', 'confirmed_at' => 'immutable_datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
