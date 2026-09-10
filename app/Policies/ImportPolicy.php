<?php

namespace App\Policies;

use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;

class ImportPolicy
{
    /**
     * Determine if the user can view the import.
     */
    public function view(User $user, Import $import): bool
    {
        // Super admin can view any import
        if ($user->hasRole(Role::SUPER_ADMIN)) {
            return true;
        }

        // Owner can view their own import
        return $import->created_by === $user->id;
    }

    /**
     * Determine if the user can cancel the import.
     */
    public function cancel(User $user, Import $import): bool
    {
        // Same logic as view - super admin or owner
        return $this->view($user, $import);
    }

    /**
     * Determine if the user can download import results.
     */
    public function download(User $user, Import $import): bool
    {
        // Same logic as view - super admin or owner
        return $this->view($user, $import);
    }
}
