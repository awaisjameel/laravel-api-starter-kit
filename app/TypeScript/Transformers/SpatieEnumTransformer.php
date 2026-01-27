<?php

declare(strict_types=1);

namespace App\TypeScript\Transformers;

use ReflectionClass;
use Spatie\Enum\Enum;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\Transformers\Transformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;

final readonly class SpatieEnumTransformer implements Transformer
{
    public function __construct(private TypeScriptTransformerConfig $config) {}

    public function transform(ReflectionClass $class, string $name): ?TransformedType
    {
        if ($class->isSubclassOf(Enum::class) === false) {
            return null;
        }

        return $this->config->shouldTransformToNativeEnums()
            ? $this->toEnum($class, $name)
            : $this->toType($class, $name);
    }

    private function toEnum(ReflectionClass $class, string $name): TransformedType
    {
        /** @var class-string<Enum> $enum */
        $enum = $class->getName();

        // Spatie Enum returns: [0 => 'Admin', 1 => 'User']
        $map = $enum::toArray();

        $options = array_map(
            fn ($value, string $label): string => sprintf(
                "%s = '%s'",
                $label,
                (string) $value
            ),
            array_keys($map),
            array_values($map)
        );

        return TransformedType::create(
            class: $class,
            name: $name,
            transformed: implode(",\n    ", $options),
            keyword: 'enum'
        );
    }

    private function toType(ReflectionClass $class, string $name): TransformedType
    {
        /** @var Enum $enum */
        $enum = $class->getName();

        $options = array_map(
            fn (int|string $enum): string => sprintf("'%s'", $enum),
            array_keys($enum::toArray())
        );

        return TransformedType::create(
            $class,
            $name,
            implode(' | ', $options)
        );
    }
}
