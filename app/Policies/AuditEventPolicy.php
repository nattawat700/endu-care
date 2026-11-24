<?php

namespace App\Policies;

use App\Models\AuditEvent;
use App\Models\User;

class AuditEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->suspended_at === null
            && ($user->activeBranchMemberships()->whereIn('role', AuditEvent::branchRoles())->exists()
                || $user->memberships()->whereIn('role', AuditEvent::organizationRoles())->exists());
    }
}
