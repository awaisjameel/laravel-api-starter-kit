<?php

declare(strict_types=1);

namespace App\TypeScript\Collectors;

use App\TypeScript\Transformers\ResourceResponseAliasTransformer;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Collectors\Collector;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\TypeReflectors\ClassTypeReflector;

final class ResourceResponseAliasCollector extends Collector
{
    public function getTransformedType(ReflectionClass $class): ?TransformedType
    {
        if ($class->isSubclassOf(JsonResource::class) === false || $class->isAbstract()) {
            return null;
        }

        $reflector = ClassTypeReflector::create($class);
        $name = $reflector->getName() ?? $class->getShortName();

        $transformer = $this->config->buildTransformer(ResourceResponseAliasTransformer::class);

        return $transformer->transform($class, $name);
    }
}
