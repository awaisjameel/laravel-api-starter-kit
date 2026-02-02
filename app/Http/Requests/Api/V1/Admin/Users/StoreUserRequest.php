<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Users;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Spatie\Enum\Laravel\Rules\EnumRule as SpatieEnumRule;

/**
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $password_confirmation
 * @property UserRole $role
 */
final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', new SpatieEnumRule(UserRole::class)],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', 'regex:/[@$!%*#?&]/'],
        ];
    }
}
