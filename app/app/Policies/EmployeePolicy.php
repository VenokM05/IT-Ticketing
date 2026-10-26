<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Employee Directory is administrator-only (PRD §10.7). Technicians have no
 * access unless explicitly authorized in a later release.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
