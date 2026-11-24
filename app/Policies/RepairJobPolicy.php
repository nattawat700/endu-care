<?php

namespace App\Policies;

use App\Enums\BranchPermission;
use App\Models\RepairJob;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RepairJobPolicy
{
    public function view(User $user, RepairJob $repair): Response
    {
        $role = $repair->branch->roleFor($user);

        return match (true) {
            $role?->allows(BranchPermission::ReadRepairs) => Response::allow(),
            $role?->allows(BranchPermission::ReadAssignedRepairs) && $repair->technician_id === $user->getKey() => Response::allow(),
            default => Response::denyAsNotFound(),
        };
    }
}
