<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Users;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule as UniqueRule;
use Spatie\Enum\Laravel\Rules\EnumRule as SpatieEnumRule;

/**
 * @property string|null $name
 * @property string|null $email
 * @property string|null $password
 * @property string|null $password_confirmation
 * @property UserRole|null $role
 */
final class UpdateUserRequest extends FormRequest
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
        $userId = (int) $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', UniqueRule::unique('users', 'email')->ignore($userId)],
            'role' => ['sometimes', 'required', new SpatieEnumRule(UserRole::class)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', 'regex:/[@$!%*#?&]/'],
        ];
    }
}
