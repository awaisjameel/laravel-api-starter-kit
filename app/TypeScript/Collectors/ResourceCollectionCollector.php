<?php

declare(strict_types=1);

namespace App\TypeScript\Collectors;

use App\TypeScript\Transformers\ResourceCollectionTransformer;
use Illuminate\Http\Resources\Json\ResourceCollection as LaravelResourceCollection;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Collectors\Collector;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\TypeReflectors\ClassTypeReflector;

final class ResourceCollectionCollector extends Collector
{
    public function getTransformedType(ReflectionClass $class): ?TransformedType
    {
        if ($class->isSubclassOf(LaravelResourceCollection::class) === false || $class->isAbstract()) {
            return null;
        }

        $reflector = ClassTypeReflector::create($class);
        $name = $reflector->getName() ?? $class->getShortName();

        $transformer = $this->config->buildTransformer(ResourceCollectionTransformer::class);

        return $transformer->transform($class, $name);
    }
}
