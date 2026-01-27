<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Validation\Rules\Enum;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UserData extends Data
{
    public int $id;

    public CarbonImmutable $created_at;

    public CarbonImmutable $updated_at;

    public function __construct(
        #[Rule(['required', 'string'])]
        public string $name,

        #[Rule(['required', 'string', 'email', 'max:255'])]
        public string $email,

        #[Rule(['required', 'string', new Enum(UserRole::class)])]
        #[WithCast(EnumCast::class, type: UserRole::class)]
        public UserRole $role,

        #[Rule(['nullable', 'date'])]
        #[WithCast(EnumCast::class, type: Carbon::class)]
        public ?DateTimeImmutable $email_verified_at,

        #[Rule(['required', 'string', 'min:8', 'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/', 'regex:/[@$!%*#?&]/'])]
        public ?string $password,
    ) {}

    public static function fromModel(User $user): self
    {
        $userData = new self(
            name: $user->name,
            email: $user->email,
            role: $user->role,
            email_verified_at: $user->email_verified_at,
            password: null, // $user->password is hashed
        );

        $userData->id = $user->id;
        $userData->updated_at = CarbonImmutable::parse($user->updated_at);
        $userData->created_at = CarbonImmutable::parse($user->created_at);

        return $userData;
    }
}
