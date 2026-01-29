<?php

declare(strict_types=1);

namespace App\TypeScript\Collectors;

use App\TypeScript\Transformers\FormRequestTransformer;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Collectors\Collector;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\TypeReflectors\ClassTypeReflector;

final class FormRequestCollector extends Collector
{
    public function getTransformedType(ReflectionClass $class): ?TransformedType
    {
        if ($class->isSubclassOf(FormRequest::class) === false || $class->isAbstract()) {
            return null;
        }

        $reflector = ClassTypeReflector::create($class);
        $name = $reflector->getName() ?? $class->getShortName();

        $transformer = $this->config->buildTransformer(FormRequestTransformer::class);

        return $transformer->transform($class, $name);
    }
}
