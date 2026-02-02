<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Enums\UserRole;
use App\Models\User;

final class CreateUser
{
    public function execute(string $name, string $email, UserRole $role, string $password): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => $password, // hashed via model cast
        ]);
    }
}
