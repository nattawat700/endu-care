<?php

namespace App\Policies;

use App\Enums\BranchPermission;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BranchPolicy
{
    public function permission(User $user, Branch $branch, BranchPermission $permission): Response
    {
        $role = $branch->roleFor($user);

        return match (true) {
            $role === null => Response::denyAsNotFound(),
            $role->allows($permission) => Response::allow(),
            default => Response::deny(),
        };
    }

    public function reviewQuotations(User $user, Branch $branch): Response
    {
        return $this->permission($user, $branch, BranchPermission::ReviewQuotation);
    }
}
