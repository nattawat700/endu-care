<?php

namespace App\Models;

use App\Enums\BranchRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['email', 'name', 'role', 'invited_by', 'token_hash', 'expires_at', 'accepted_at'])]
#[Hidden(['token_hash'])]
class StaffInvitation extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public static function active(string $token): self
    {
        return self::where('token_hash', hash('sha256', $token))->whereNull('accepted_at')
            ->where('expires_at', '>', now())->firstOrFail();
    }

    protected function casts(): array
    {
        return ['role' => BranchRole::class, 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
