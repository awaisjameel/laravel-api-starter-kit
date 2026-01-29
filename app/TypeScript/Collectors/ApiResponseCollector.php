<?php

declare(strict_types=1);

namespace App\TypeScript\Collectors;

use App\Traits\ApiResponse;
use App\TypeScript\Transformers\ApiResponseTransformer;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Collectors\Collector;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\TypeReflectors\ClassTypeReflector;

final class ApiResponseCollector extends Collector
{
    public function getTransformedType(ReflectionClass $class): ?TransformedType
    {
        if ($class->isTrait() === false || $class->getName() !== ApiResponse::class) {
            return null;
        }

        $reflector = ClassTypeReflector::create($class);
        $name = $reflector->getName() ?? 'ApiResponse<T>';

        $transformer = $this->config->buildTransformer(ApiResponseTransformer::class);

        return $transformer->transform($class, $name);
    }
}
