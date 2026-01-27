<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\Enum\Laravel\Enum;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * @method static self Admin()
 * @method static self User()
 */
#[TypeScript]
final class UserRole extends Enum
{
    protected static function values(): array
    {
        return [
            'Admin' => 0,
            'User' => 1,
        ];
    }
}
