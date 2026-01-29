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
            $type = $dtoPropertyTypes[$key] ?? null;

            if ($type === null) {
                // Nested resource/collection heuristics first
                $nestedTs = $this->detectNestedResourceTs($expr);
                if ($nestedTs !== null) {
                    $type = $nestedTs;
                } elseif (str_contains($expr, 'toIso8601String()')) {
                    // Heuristics for common patterns in resources
                    $type = str_contains($expr, '?->') ? 'string | null' : 'string';
                } elseif (preg_match('/\?->/', $expr) === 1) {
                    $type = 'unknown | null';
                } elseif (preg_match('/^\s*(true|false)\s*$/i', $expr) === 1) {
                    $type = 'boolean';
                } elseif (preg_match('/^\s*[-]?\d+(?:\.\d+)?\s*$/', $expr) === 1) {
                    $type = 'number';
                } elseif (preg_match('/^\s*\'([^\']*)\'\s*$/', $expr) === 1 || preg_match('/^\s*\"([^\"]*)\"\s*$/', $expr) === 1) {
                    $type = 'string';
                }
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

    private function detectNestedResourceTs(string $expr): ?string
    {
        // map(fn (...) => new SomeResource(...)) => Array<SomeResource>
        if (preg_match('/->map\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*new\s+([A-Za-z_\\][A-Za-z0-9_\\]*)/s', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            if (str_ends_with($short, 'Resource')) {
                return sprintf('Array<%s>', $short);
            }
        }

        // map(fn (...) => SomeResource::make(...)) => Array<SomeResource>
        if (preg_match('/->map\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*([A-Za-z_\\][A-Za-z0-9_\\]*)::make\s*\(/s', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            if (str_ends_with($short, 'Resource')) {
                return sprintf('Array<%s>', $short);
            }
        }

        // pluck(...)->map(fn => new SomeResource(...)) => Array<SomeResource>
        if (preg_match('/->pluck\s*\([^)]*\)\s*->map\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*new\s+([A-Za-z_\\][A-Za-z0-9_\\]*)/s', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            if (str_ends_with($short, 'Resource')) {
                return sprintf('Array<%s>', $short);
            }
        }

        // pluck(...)->map(fn => SomeResource::make(...)) => Array<SomeResource>
        if (preg_match('/->pluck\s*\([^)]*\)\s*->map\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*([A-Za-z_\\][A-Za-z0-9_\\]*)::make\s*\(/s', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            if (str_ends_with($short, 'Resource')) {
                return sprintf('Array<%s>', $short);
            }
        }

        // transform(fn (...) => new SomeResource(...)) => Array<SomeResource>
        if (preg_match('/->transform\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*new\s+([A-Za-z_\\][A-Za-z0-9_\\]*)/s', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            if (str_ends_with($short, 'Resource')) {
                return sprintf('Array<%s>', $short);
            }
        }

        // transform(fn (...) => SomeResource::make(...)) => Array<SomeResource>
        if (preg_match('/->transform\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*([A-Za-z_\\][A-Za-z0-9_\\]*)::make\s*\(/s', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            if (str_ends_with($short, 'Resource')) {
                return sprintf('Array<%s>', $short);
            }
        }

        // flatMap(fn (...) => new SomeResource(...)) => Array<SomeResource>
        if (preg_match('/->flatMap\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*new\s+([A-Za-z_\\][A-Za-z0-9_\\]*)/s', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            if (str_ends_with($short, 'Resource')) {
                return sprintf('Array<%s>', $short);
            }
        }

        // flatMap(fn (...) => SomeResource::make(...)) => Array<SomeResource>
        if (preg_match('/->flatMap\s*\((?:fn|function)\s*\([^)]*\)\s*=>\s*([A-Za-z_\\][A-Za-z0-9_\\]*)::make\s*\(/s', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            if (str_ends_with($short, 'Resource')) {
                return sprintf('Array<%s>', $short);
            }
        }

        // SomeResource::collection(...)
        if (preg_match('/([A-Za-z_\\][A-Za-z0-9_\\]*)::collection\s*\(/', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            $res = $this->normalizeResourceShort($short);

            return sprintf('Array<%s>', $res);
        }

        // new SomeCollection(...) or new SomeResource(...)
        if (preg_match('/new\s+([A-Za-z_\\][A-Za-z0-9_\\]*)\s*\(/', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            if (str_ends_with($short, 'Collection')) {
                $base = mb_substr($short, 0, -10);

                return sprintf('Array<%sResource>', $base);
            }

            if (str_ends_with($short, 'Resource')) {
                return $short;
            }
        }

        // SomeResource::make(...)
        if (preg_match('/([A-Za-z_\\][A-Za-z0-9_\\]*)::make\s*\(/', $expr, $m) === 1) {
            $short = $this->shortClass($m[1]);
            if (str_ends_with($short, 'Resource')) {
                return $short;
            }
        }

        // Generic JsonResource::collection(...) fallback
        if (preg_match('/JsonResource::collection\s*\(/', $expr) === 1) {
            return 'Array<unknown>';
        }

        // map->relation shorthand without explicit resource creation => Array<unknown>
        if (preg_match('/->map->\s*[A-Za-z_]\w*\s*\(/', $expr) === 1) {
            return 'Array<unknown>';
        }

        return null;
    }

    private function shortClass(string $fqn): string
    {
        $parts = explode('\\\\', str_replace('\\', '\\\\', $fqn));
        $last = end($parts);

        return $last !== false ? $last : $fqn;
    }

    private function normalizeResourceShort(string $short): string
    {
        if (str_ends_with($short, 'Resource')) {
            return $short;
        }

        if (str_ends_with($short, 'Collection')) {
            $base = mb_substr($short, 0, -10);

            return $base.'Resource';
        }

        return $short;
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
