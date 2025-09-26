<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Models\User;
use App\Support\ResolvesRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    use ResolvesRoles;

    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request->user());

        $users = User::query()->orderBy('name')->get();

        return response()->json($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return response()->json($user, Response::HTTP_CREATED);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->fill($request->validated());
        $user->save();

        return response()->json($user);
    }

    public function destroy(Request $request, User $user): Response
    {
        $this->ensureAdmin($request->user());

        $user->delete();

        return response()->noContent();
    }

    public function assignRole(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $user->role = Role::from($request->validated()['role']);
        $user->save();

        return response()->json($user);
    }

    private function ensureAdmin(mixed $user): void
    {
        if ($this->resolveRole($user) !== Role::Admin) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
