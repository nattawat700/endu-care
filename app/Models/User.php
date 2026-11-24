<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\BranchRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery_hashes', 'mfa_setup_code_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function memberships(): HasMany
    {
        return $this->hasMany(BranchMembership::class);
    }

    public function activeBranchMemberships(): HasMany
    {
        return $this->memberships()->whereHas('branch.organization', fn ($organizations) => $organizations->whereNull('suspended_at'));
    }

    public function requiresStaffMfa(): bool
    {
        return $this->mfa_secret !== null || $this->memberships()->whereIn('role',
            array_filter(BranchRole::cases(), fn (BranchRole $role) => $role->requiresMfa()),
        )->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspended_at' => 'datetime',
            'staff_session_version' => 'integer',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_hashes' => 'array',
            'mfa_last_step' => 'integer',
            'mfa_setup_expires_at' => 'datetime',
        ];
    }
}
