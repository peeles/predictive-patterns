<?php

namespace App\Providers;

use App\Support\ResolvesRoles;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class HorizonServiceProvider extends ServiceProvider
{
    use ResolvesRoles;

    public function boot(): void
    {
        Horizon::auth(function ($request): bool {
            $user = $request->user();

            if ($user === null) {
                return false;
            }

            $role = $this->resolveRole($user);

            return $role->canManageModels();
        });
    }
}
