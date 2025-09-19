<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Dataset;
use App\Support\ResolvesRoles;

class DatasetPolicy
{
    use ResolvesRoles;

    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, Dataset $dataset): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        $role = $this->resolveRole($user);

        return in_array($role, [Role::Admin, Role::Analyst], true);
    }

    public function update(mixed $user, Dataset $dataset): bool
    {
        $role = $this->resolveRole($user);

        return $role === Role::Admin;
    }

    public function delete(mixed $user, Dataset $dataset): bool
    {
        $role = $this->resolveRole($user);

        return $role === Role::Admin;
    }
}
