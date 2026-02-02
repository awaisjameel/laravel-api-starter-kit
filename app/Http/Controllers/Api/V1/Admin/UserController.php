<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\Users\CreateUser;
use App\Actions\Admin\Users\DeleteUser;
use App\Actions\Admin\Users\UpdateUser;
use App\Enums\UserRole;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Admin\Users\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\Users\UpdateUserRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = QueryBuilder::for(User::class)
            ->allowedFilters([
                AllowedFilter::partial('name'),
                AllowedFilter::partial('email'),
                AllowedFilter::exact('role'),
            ])
            ->allowedSorts(['id', 'name', 'email', 'created_at'])
            ->defaultSort('-id');

        $paginator = $query->paginate(perPage: (int) ($request->query('per_page', 15)))->appends($request->query());

        return $this->success(UserCollection::make($paginator));
    }

    public function store(StoreUserRequest $request, CreateUser $action): JsonResponse
    {
        /** @var UserRole $role */
        $role = $request->input('role');

        $user = $action->execute(
            name: $request->input('name'),
            email: $request->input('email'),
            role: $role,
            password: $request->input('password'),
        );

        return $this->created(new UserResource($user));
    }

    public function show(User $user): JsonResponse
    {
        return $this->success(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $action): JsonResponse
    {
        $attributes = [];

        if ($request->has('name')) {
            $attributes['name'] = (string) $request->input('name');
        }

        if ($request->has('email')) {
            $attributes['email'] = (string) $request->input('email');
        }

        if ($request->has('role')) {
            /** @var UserRole $role */
            $role = $request->input('role');
            $attributes['role'] = $role;
        }

        if ($request->has('password')) {
            $attributes['password'] = (string) $request->input('password');
        }

        $updated = $action->execute($user, $attributes);

        return $this->success(new UserResource($updated));
    }

    public function destroy(User $user, DeleteUser $action): JsonResponse
    {
        $action->execute($user);

        return $this->noContent();
    }
}
