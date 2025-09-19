<?php

namespace App\Support;

use App\Auth\TokenUser;
use App\Enums\Role;

trait ResolvesRoles
{
    private function resolveRole(mixed $user): Role
    {
        if ($user instanceof TokenUser) {
            return $user->role();
        }

        if (is_object($user)) {
            $role = null;

            if (method_exists($user, 'role')) {
                $role = $user->role();
            } elseif (method_exists($user, 'getAttribute')) {
                $role = $user->getAttribute('role');
            } elseif (property_exists($user, 'role')) {
                $role = $user->role;
            }

            if ($role instanceof Role) {
                return $role;
            }

            if (is_string($role)) {
                return Role::tryFrom($role) ?? Role::Viewer;
            }
        }

        return Role::Viewer;
    }
}
