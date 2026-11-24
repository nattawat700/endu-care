<?php

namespace App\Policies;

use App\Enums\BranchRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrganizationPolicy
{
    public function create(User $user): bool
    {
        return $user->suspended_at === null && $user->memberships()->where('role', BranchRole::Hq)
            ->whereHas('branch.organization', fn ($organizations) => $organizations->whereNull('suspended_at'))->exists();
    }

    public function manage(User $user, Organization $organization): Response
    {
        return $user->suspended_at === null && $user->memberships()
            ->whereIn('role', [BranchRole::Owner, BranchRole::Hq])
            ->whereHas('branch', fn ($branches) => $branches->whereBelongsTo($organization))
            ->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
