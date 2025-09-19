<?php

namespace App\Auth;

use App\Enums\Role;
use Illuminate\Auth\GenericUser;

/**
 * @extends GenericUser<array<string, mixed>>
 */
class TokenUser extends GenericUser
{
    public static function fromRole(Role $role, ?string $name = null): self
    {
        return new self([
            'id' => sprintf('token-%s', $role->value),
            'name' => $name ?? $role->value,
            'role' => $role,
        ]);
    }

    public function role(): Role
    {
        $role = $this->attributes['role'] ?? Role::Viewer;

        return $role instanceof Role ? $role : Role::tryFrom((string) $role) ?? Role::Viewer;
    }
}
