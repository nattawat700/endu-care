<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['previous_user_id', 'next_user_id', 'changed_by', 'reason'])]
class PosCreditChange extends Model
{
    public function previous(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_user_id');
    }

    public function next(): BelongsTo
    {
        return $this->belongsTo(User::class, 'next_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
