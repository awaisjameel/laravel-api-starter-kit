<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class UpdateUser
{
    /**
     * @param  array{name?: string, email?: string, role?: UserRole, password?: string}  $attributes
     */
    public function execute(User $user, array $attributes): User
    {
        if (array_key_exists('password', $attributes)) {
            if ($attributes['password'] === null) {
                unset($attributes['password']);
            } else {
                $attributes['password'] = Hash::make($attributes['password']);
            }
        }

        if (array_key_exists('password_confirmation', $attributes)) {
            unset($attributes['password_confirmation']);
        }

        $user->fill($attributes);
        $user->save();

        return $user;
    }
}
