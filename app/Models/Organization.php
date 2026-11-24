<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'owner_id', 'operator_type', 'document_profile', 'approval_rules', 'suspended_at'])]
class Organization extends Model
{
    public const OPERATOR_TYPES = ['business' => 'นิติบุคคล', 'individual' => 'ผู้ประกอบการบุคคลธรรมดา'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrganizationStatusEvent::class);
    }

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime', 'document_profile' => 'array', 'approval_rules' => 'array'];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
