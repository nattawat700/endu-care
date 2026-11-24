<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['revision', 'submission_id', 'recorded_by', 'cause', 'repair_plan', 'expected_completion_at', 'items', 'total_satang'])]
class RepairAssessment extends Model
{
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'expected_completion_at' => 'immutable_datetime',
            'items' => 'array',
            'total_satang' => 'integer',
        ];
    }
}
