<?php

declare(strict_types=1);

namespace App\TypeScript\Transformers;

use App\Traits\ApiResponse;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\Transformers\Transformer;

final readonly class ApiResponseTransformer implements Transformer
{
    public function transform(ReflectionClass $class, string $name): ?TransformedType
    {
        if ($class->getName() !== ApiResponse::class) {
            return null;
        }

        $type = '{ success: true; message: string; data: T } | { success: false; message: string; errors?: Record<string, unknown> }';

        return TransformedType::create(
            $class,
            'ApiResponse<T>',
            $type
        );
    }
}
