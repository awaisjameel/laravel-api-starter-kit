<?php

declare(strict_types=1);

namespace App\TypeScript\Transformers;

use DateTimeInterface;
use Illuminate\Http\Resources\Json\JsonResource;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Spatie\Enum\Enum as SpatieEnum;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\Transformers\Transformer;
use Throwable;

final readonly class JsonResourceTransformer implements Transformer
{
    private DocBlockFactory $docBlockFactory;

    public function __construct()
    {
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    public function transform(ReflectionClass $class, string $name): ?TransformedType
    {
        if ($class->isSubclassOf(JsonResource::class) === false || $class->isAbstract()) {
            return null;
        }

        $method = $class->hasMethod('toArray') ? $class->getMethod('toArray') : null;
        if ($method === null) {
            return null;
        }

        $file = $class->getFileName();
        if ($file === false) {
            return null;
        }

        $fileContent = @file_get_contents($file);
        if ($fileContent === false) {
            return null;
        }

        $methodStart = $method->getStartLine();
        $methodEnd = $method->getEndLine();
        $lines = explode("\n", $fileContent);
        $methodBody = implode("\n", array_slice($lines, max(0, $methodStart - 1), max(0, $methodEnd - $methodStart + 1)));

        $arrayContent = $this->extractReturnedArrayContent($methodBody);
        if ($arrayContent === null) {
            return null;
        }

        $entries = $this->parseArrayEntries($arrayContent);

        // Try to resolve a matching Data DTO for field type hints
        $dtoPropertyTypes = $this->resolveDtoPropertyTypes($class);

        $props = [];
        foreach ($entries as $key => $expr) {
            $type = $this->inferExpressionType($expr);
            if ($type === null) {
                $type = $dtoPropertyTypes[$key] ?? null;
            }

            $isOptional = str_contains($expr, 'whenLoaded(') || preg_match('/\bwhen\s*\(/', $expr) === 1;
            $props[] = sprintf(
                '    %s%s: %s;',
                $this->formatPropertyName($key),
                $isOptional ? '?' : '',
                $type ?? 'unknown'
            );
        }

        if ($props === []) {
            return null;
        }

        $base = "{\n".implode("\n", $props)."\n}";
        $alias = sprintf("\nexport type %sResponse = ApiResponse<%s>", $name, $name);

        return TransformedType::create(
            $class,
            $name,
            $base.$alias
        );
    }

    private function inferExpressionType(string $expr): ?string
    {
        // Nested resource/collection heuristics first
        $nestedTs = $this->detectNestedResourceTs($expr);
        if ($nestedTs !== null) {
            return $nestedTs;
        }

        if (str_contains($expr, 'toIso8601String()')) {
            // Heuristics for common patterns in resources
            return str_contains($expr, '?->') ? 'string | null' : 'string';
        }

        if (preg_match('/\?->/', $expr) === 1) {
            return 'unknown | null';
        }

        if (preg_match('/^\s*(true|false)\s*$/i', $expr) === 1) {
            return 'boolean';
        }

        if (preg_match('/^\s*[-]?\d+(?:\.\d+)?\s*$/', $expr) === 1) {
            return 'number';
        }

        if (preg_match('/^\s*\'([^\']*)\'\s*$/', $expr) === 1 || preg_match('/^\s*\"([^\"]*)\"\s*$/', $expr) === 1) {
            return 'string';
        }

        return null;
    }

    private function detectNestedResourceTs(string $expr): ?string
    {
        $mapped = $this->detectMappedResourceTs($expr);
        if ($mapped !== null) {
            return $mapped;
        }

        $factory = $this->detectResourceFactoryTs($expr);
        if ($factory !== null) {
            return $factory;
        }

        // map->relation shorthand without explicit resource creation => Array<unknown>
        if (preg_match('/->map->\s*[A-Za-z_]\w*\s*\(/', $expr) === 1) {
            return 'Array<unknown>';
        }

        return null;
    }

    private function detectMappedResourceTs(string $expr): ?string
    {
        $patterns = [
            ['method' => 'map', 'flatMap' => false],
            ['method' => 'transform', 'flatMap' => false],
            ['method' => 'flatMap', 'flatMap' => true],
            ['method' => 'pluck\\s*\\([^)]*\\)\\s*->map', 'flatMap' => false],
        ];

        foreach ($patterns as $pattern) {
            $type = $this->detectCallbackResourceTs($expr, $pattern['method'], $pattern['flatMap']);
            if ($type !== null) {
                return $type;
            }
        }

        return null;
    }

    private function detectCallbackResourceTs(string $expr, string $methodPattern, bool $flatMap): ?string
    {
        $method = '->'.$methodPattern;

        $newPattern = '/'.$method.'\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*new\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/s';
        if (preg_match($newPattern, $expr, $m) === 1) {
            $inner = $this->resolveResourceFactoryType($m[1], false);
            if ($inner !== null) {
                return $this->wrapMappedType($inner, $flatMap);
            }
        }

        $makePattern = '/'.$method.'\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::make\s*\(/s';
        if (preg_match($makePattern, $expr, $m) === 1) {
            $inner = $this->resolveResourceFactoryType($m[1], false);
            if ($inner !== null) {
                return $this->wrapMappedType($inner, $flatMap);
            }
        }

        $collectionPattern = '/'.$method.'\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::collection\s*\(/s';
        if (preg_match($collectionPattern, $expr, $m) === 1) {
            $inner = $this->resolveResourceFactoryType($m[1], true);
            if ($inner !== null) {
                return $this->wrapMappedType($inner, $flatMap);
            }
        }

        return null;
    }

    private function detectResourceFactoryTs(string $expr): ?string
    {
        if (preg_match('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::collection\s*\(/', $expr, $m) === 1) {
            $type = $this->resolveResourceFactoryType($m[1], true);
            if ($type !== null) {
                return $type;
            }
        }

        if (preg_match('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::make\s*\(/', $expr, $m) === 1) {
            $type = $this->resolveResourceFactoryType($m[1], false);
            if ($type !== null) {
                return $type;
            }
        }

        if (preg_match('/new\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*\(/', $expr, $m) === 1) {
            $type = $this->resolveResourceFactoryType($m[1], false);
            if ($type !== null) {
                return $type;
            }
        }

        // Generic JsonResource::collection(...) fallback
        if (preg_match('/JsonResource::collection\s*\(/', $expr) === 1) {
            return 'Array<unknown>';
        }

        return null;
    }

    private function wrapMappedType(string $innerType, bool $flatMap): string
    {
        if ($flatMap) {
            $unwrapped = $this->unwrapArrayType($innerType);

            return sprintf('Array<%s>', $unwrapped ?? $innerType);
        }

        return sprintf('Array<%s>', $innerType);
    }

    private function unwrapArrayType(string $type): ?string
    {
        if (preg_match('/^Array<(.+)>$/', $type, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function resolveResourceFactoryType(string $class, bool $collectionFactory): ?string
    {
        $short = $this->shortClass($class);

        if (str_ends_with($short, 'Collection')) {
            $base = mb_substr($short, 0, -10);
            $resource = $base.'Resource';

            return sprintf('Array<%s>', $resource);
        }

        if (str_ends_with($short, 'Resource')) {
            return $collectionFactory ? sprintf('Array<%s>', $short) : $short;
        }

        return null;
    }

    private function shortClass(string $fqn): string
    {
        $parts = explode('\\\\', str_replace('\\', '\\\\', $fqn));
        $last = end($parts);

        return $last !== false ? $last : $fqn;
    }

    private function extractReturnedArrayContent(string $methodBody): ?string
    {
        $returnPos = mb_strpos($methodBody, 'return');
        if ($returnPos === false) {
            return null;
        }

        $afterReturn = mb_substr($methodBody, $returnPos);
        $firstBracket = mb_strpos($afterReturn, '[');
        if ($firstBracket === false) {
            return null;
        }

        $content = mb_substr($afterReturn, $firstBracket);
        $depth = 0;
        $len = mb_strlen($content);
        $endIndex = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $content[$i];
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    $endIndex = $i;
                    break;
                }
            }
        }

        if ($endIndex === null) {
            return null;
        }

        return mb_trim(mb_substr($content, 1, $endIndex - 1));
    }

    /**
     * @return array<string, string> key => expression
     */
    private function parseArrayEntries(string $content): array
    {
        $entries = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringQuote = '';
        $len = mb_strlen($content);
        for ($i = 0; $i < $len; $i++) {
            $ch = $content[$i];
            if ($inString) {
                $current .= $ch;
                if ($ch === $stringQuote && ($i === 0 || $content[$i - 1] !== '\\')) {
                    $inString = false;
                }

                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inString = true;
                $stringQuote = $ch;
                $current .= $ch;

                continue;
            }

            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
            }

            if ($ch === ',' && $depth === 0) {
                $this->appendEntry($entries, $current);
                $current = '';

                continue;
            }

            $current .= $ch;
        }

        if (mb_trim($current) !== '') {
            $this->appendEntry($entries, $current);
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function appendEntry(array &$entries, string $raw): void
    {
        $raw = mb_trim($raw);
        if ($raw === '') {
            return;
        }

        if (! str_contains($raw, '=>')) {
            return; // not a key-value entry
        }

        [$k, $v] = array_map(trim(...), explode('=>', $raw, 2));
        $key = preg_match('/^[\'\"](.+)[\'\"]$/', $k, $m) === 1 ? $m[1] : $k;

        $entries[$key] = mb_rtrim($v, ",\n\r ");
    }

    /**
     * @return array<string, string>
     */
    private function resolveDtoPropertyTypes(ReflectionClass $resourceClass): array
    {
        $dtoClass = $this->guessDtoClassFromResource($resourceClass);
        if ($dtoClass === null || ! class_exists($dtoClass)) {
            return [];
        }

        $ref = new ReflectionClass($dtoClass);
        $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);
        $types = [];
        foreach ($props as $prop) {
            $types[$prop->getName()] = $this->reflectionTypeToTs($prop->getType());
        }

        return $types;
    }

    private function guessDtoClassFromResource(ReflectionClass $resourceClass): ?string
    {
        $doc = $resourceClass->getDocComment();
        if ($doc !== false) {
            try {
                $db = $this->docBlockFactory->create($doc);
                $mixins = $db->getTagsByName('mixin');
                foreach ($mixins as $mixin) {
                    if (method_exists($mixin, 'getDescription')) {
                        $fqcn = mb_trim((string) $mixin->getDescription());
                        if ($fqcn !== '') {
                            $parts = explode('\\', $fqcn);
                            $short = end($parts) ?: null;
                            if ($short !== null) {
                                return 'App\\Data\\'.$short.'Data';
                            }
                        }
                    }
                }
            } catch (Throwable) {
                // ignore
            }
        }

        // Fallback: infer from resource class name, e.g., UserResource -> UserData
        $short = $resourceClass->getShortName();
        if (str_ends_with($short, 'Resource')) {
            $base = mb_substr($short, 0, -8);

            return 'App\\Data\\'.$base.'Data';
        }

        return null;
    }

    private function reflectionTypeToTs(?ReflectionType $type): string
    {
        if (! $type instanceof ReflectionType) {
            return 'unknown';
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $inner) {
                $parts[] = $this->reflectionTypeToTs($inner);
            }

            // Ensure unique and preserve null as a union, not optional
            $parts = array_values(array_unique($parts));

            return implode(' | ', $parts);
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            if ($type->isBuiltin()) {
                return match ($name) {
                    'int', 'float' => 'number',
                    'string' => 'string',
                    'bool' => 'boolean',
                    'null' => 'null',
                    'array' => 'Array<unknown>',
                    default => 'unknown',
                };
            }

            // Class types
            if (is_a($name, DateTimeInterface::class, true)) {
                return $type->allowsNull() ? 'string | null' : 'string';
            }

            if (is_a($name, SpatieEnum::class, true)) {
                $parts = explode('\\', $name);

                return end($parts) ?: 'unknown';
            }

            $parts = explode('\\', $name);
            $ts = end($parts) ?: 'unknown';

            return $type->allowsNull() ? $ts.' | null' : $ts;
        }

        return 'unknown';
    }

    private function formatPropertyName(string $name): string
    {
        if (preg_match('/^[A-Za-z_\\$][A-Za-z0-9_\\$]*$/', $name) === 1) {
            return $name;
        }

        return "'".str_replace("'", "\\'", $name)."'";
    }
}
