<?php

declare(strict_types=1);

namespace App\TypeScript\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\Transformers\Transformer;

final readonly class ResourceResponseAliasTransformer implements Transformer
{
    public function transform(ReflectionClass $class, string $name): ?TransformedType
    {
        if ($class->isSubclassOf(JsonResource::class) === false || $class->isAbstract()) {
            return null;
        }

        $alias = $name.'Response';
        $target = sprintf('ApiResponse<%s>', $name);

        return TransformedType::create(
            $class,
            $alias,
            $target
        );
    }
}
