<?php

declare(strict_types=1);

namespace App\TypeScript\Transformers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum as LaravelEnumRule;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Null_;
use phpDocumentor\Reflection\Types\Nullable;
use ReflectionClass;
use ReflectionObject;
use Spatie\Enum\Laravel\Rules\EnumRule as SpatieEnumRule;
use Spatie\TypeScriptTransformer\Actions\TranspileTypeToTypeScriptAction;
use Spatie\TypeScriptTransformer\Structures\MissingSymbolsCollection;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\Transformers\Transformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Stringable;
use Throwable;

final readonly class FormRequestTransformer implements Transformer
{
    private const array ARRAY_RULES = ['array', 'list'];

    private const array BOOLEAN_RULES = ['boolean', 'accepted', 'declined'];

    private const array FILE_RULES = ['file', 'image', 'mimes', 'mimetypes'];

    private const array JSON_RULES = ['json'];

    private const array NUMBER_RULES = ['integer', 'numeric', 'decimal', 'digits', 'digits_between'];

    private const array STRING_RULES = [
        'string',
        'email',
        'uuid',
        'ulid',
        'date',
        'date_format',
        'timezone',
        'ip',
        'mac_address',
        'url',
        'active_url',
        'regex',
        'alpha',
        'alpha_num',
        'alpha_dash',
        'starts_with',
        'ends_with',
        'password',
        'current_password',
    ];

    private DocBlockFactory $docBlockFactory;

    public function __construct(private TypeScriptTransformerConfig $config)
    {
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    public function transform(ReflectionClass $class, string $name): ?TransformedType
    {
        if ($class->isSubclassOf(FormRequest::class) === false) {
            return null;
        }

        $missingSymbols = new MissingSymbolsCollection();
        $ruleDefinitions = $this->resolveRuleDefinitions($this->resolveRules($class));
        $docTypes = $this->resolveDocblockPropertyTypes($class);

        $properties = $this->buildProperties($ruleDefinitions, $docTypes, $missingSymbols);

        if ($properties === []) {
            return null;
        }

        $lines = array_map(
            fn (array $property): string => sprintf(
                '    %s%s: %s;',
                $this->formatPropertyName($property['name']),
                $property['optional'] ? '?' : '',
                $property['type']
            ),
            $properties
        );

        return TransformedType::create(
            $class,
            $name,
            "{\n".implode("\n", $lines)."\n}",
            $missingSymbols
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRules(ReflectionClass $class): array
    {
        if (! $class->isInstantiable() || ! $class->hasMethod('rules')) {
            return [];
        }

        try {
            $instance = $class->newInstance();
        } catch (Throwable) {
            return [];
        }

        if (! $instance instanceof FormRequest) {
            return [];
        }

        try {
            $rules = $instance->rules();
        } catch (Throwable) {
            return [];
        }

        return is_array($rules) ? $rules : [];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array{tokens: array<int, string|object>, required: bool, nullable: bool, arrayItemTokens?: array<int, string|object>}>
     */
    private function resolveRuleDefinitions(array $rules): array
    {
        $definitions = [];
        $arrayItemTokens = [];

        foreach ($rules as $field => $ruleSet) {
            if (! is_string($field)) {
                continue;
            }

            if ($this->isArrayItemField($field)) {
                $parent = $this->arrayParentField($field);
                $arrayItemTokens[$parent] = array_merge(
                    $arrayItemTokens[$parent] ?? [],
                    $this->normalizeRules($ruleSet)
                );

                continue;
            }

            $tokens = $this->normalizeRules($ruleSet);

            $definitions[$field] = [
                'tokens' => $tokens,
                'required' => $this->isRequired($tokens),
                'nullable' => $this->isNullable($tokens),
            ];
        }

        foreach ($arrayItemTokens as $parent => $tokens) {
            $definitions[$parent] ??= [
                'tokens' => [],
                'required' => false,
                'nullable' => false,
            ];

            $definitions[$parent]['arrayItemTokens'] = $tokens;
        }

        return $definitions;
    }

    /**
     * @param  array<string, array{tokens: array<int, string|object>, required: bool, nullable: bool, arrayItemTokens?: array<int, string|object>}>  $ruleDefinitions
     * @param  array<string, Type>  $docTypes
     * @return array<int, array{name: string, type: string, optional: bool}>
     */
    private function buildProperties(
        array $ruleDefinitions,
        array $docTypes,
        MissingSymbolsCollection $missingSymbols
    ): array {
        $properties = [];

        foreach ($ruleDefinitions as $field => $definition) {
            $docType = $docTypes[$field] ?? null;
            $docTypeScript = $docType ? $this->typeToTypeScript($docType, $missingSymbols) : null;
            $ruleTypeScript = $this->inferTypeFromRules(
                $definition['tokens'],
                $definition['arrayItemTokens'] ?? null,
                $missingSymbols
            );

            $typeScript = $docTypeScript ?? $ruleTypeScript;
            if ($docTypeScript !== null && $ruleTypeScript !== null) {
                $isSimpleDoc = in_array($docTypeScript, ['string', 'number', 'boolean', 'any', 'unknown'], true);
                $isSpecificRule = str_contains($ruleTypeScript, "'")
                    || str_starts_with($ruleTypeScript, 'Array<')
                    || str_starts_with($ruleTypeScript, 'Record<')
                    || str_starts_with($ruleTypeScript, '{%')
                    || ! in_array($ruleTypeScript, ['string', 'number', 'boolean', 'any', 'unknown'], true);

                $typeScript = ($isSimpleDoc && $isSpecificRule) ? $ruleTypeScript : $docTypeScript;
            }

            $typeScript ??= 'unknown';
            $isNullable = $definition['nullable'] || ($docType !== null && $this->isNullableType($docType));
            $isOptional = $this->isOptional($definition['required'], $definition['tokens'], $isNullable);

            $properties[] = [
                'name' => $field,
                'type' => $this->applyNullability($typeScript, $isNullable),
                'optional' => $isOptional,
            ];
        }

        foreach ($docTypes as $field => $docType) {
            if (array_key_exists($field, $ruleDefinitions)) {
                continue;
            }

            $typeScript = $this->typeToTypeScript($docType, $missingSymbols);
            $isNullable = $this->isNullableType($docType);

            $properties[] = [
                'name' => $field,
                'type' => $this->applyNullability($typeScript, $isNullable),
                'optional' => true,
            ];
        }

        return $properties;
    }

    /**
     * @return array<string, Type>
     */
    private function resolveDocblockPropertyTypes(ReflectionClass $class): array
    {
        $comment = $class->getDocComment();

        if ($comment === false) {
            return [];
        }

        try {
            $docBlock = $this->docBlockFactory->create($comment);
        } catch (Throwable) {
            return [];
        }

        $tags = array_merge(
            $docBlock->getTagsByName('property'),
            $docBlock->getTagsByName('property-read'),
            $docBlock->getTagsByName('property-write')
        );

        $types = [];

        foreach ($tags as $tag) {
            if (! method_exists($tag, 'getVariableName')) {
                continue;
            }

            if (! method_exists($tag, 'getType')) {
                continue;
            }

            $name = $tag->getVariableName();

            if ($name === '') {
                continue;
            }

            $types[$name] = $tag->getType();
        }

        return $types;
    }

    /**
     * @return array<int, string|object>
     */
    private function normalizeRules(mixed $rules): array
    {
        $tokens = [];

        $append = function (mixed $rule) use (&$tokens, &$append): void {
            if (is_array($rule)) {
                foreach ($rule as $nestedRule) {
                    $append($nestedRule);
                }

                return;
            }

            if (is_string($rule)) {
                $rule = mb_trim($rule);

                if ($rule === '') {
                    return;
                }

                foreach (explode('|', $rule) as $segment) {
                    $segment = mb_trim($segment);

                    if ($segment !== '') {
                        $tokens[] = $segment;
                    }
                }

                return;
            }

            if ($rule instanceof Stringable && ! $rule instanceof LaravelEnumRule && ! $rule instanceof SpatieEnumRule) {
                $stringRule = mb_trim((string) $rule);

                if ($stringRule !== '') {
                    $tokens[] = $stringRule;
                }

                return;
            }

            if (is_object($rule)) {
                $tokens[] = $rule;
            }
        };

        $append($rules);

        return $tokens;
    }

    /**
     * @param  array<int, string|object>  $tokens
     */
    private function isRequired(array $tokens): bool
    {
        $names = $this->ruleNames($tokens);

        if (in_array('sometimes', $names, true)) {
            return false;
        }

        return in_array('required', $names, true) || in_array('present', $names, true);
    }

    /**
     * @param  array<int, string|object>  $tokens
     */
    private function isNullable(array $tokens): bool
    {
        return in_array('nullable', $this->ruleNames($tokens), true);
    }

    /**
     * @param  array<int, string|object>  $tokens
     */
    private function isOptional(bool $required, array $tokens, bool $nullable): bool
    {
        if (in_array('sometimes', $this->ruleNames($tokens), true)) {
            return true;
        }

        if ($nullable && $this->config->shouldConsiderNullAsOptional()) {
            return true;
        }

        return ! $required;
    }

    /**
     * @param  array<int, string|object>  $tokens
     */
    private function ruleNames(array $tokens): array
    {
        $names = [];

        foreach ($tokens as $token) {
            if (! is_string($token)) {
                continue;
            }

            [$name] = array_pad(explode(':', $token, 2), 2, null);
            $names[] = mb_strtolower((string) $name);
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<int, string|object>  $tokens
     */
    private function inferTypeFromRules(
        array $tokens,
        ?array $arrayItemTokens,
        MissingSymbolsCollection $missingSymbols
    ): ?string {
        $enumType = $this->enumTypeFromRules($tokens, $missingSymbols);

        if ($enumType !== null) {
            return $enumType;
        }

        $inType = $this->inTypeFromRules($tokens);

        if ($inType !== null) {
            return $inType;
        }

        $names = $this->ruleNames($tokens);
        $isArray = $this->hasRule($names, self::ARRAY_RULES) || $arrayItemTokens !== null;

        if ($isArray) {
            $itemType = $arrayItemTokens
                ? $this->inferTypeFromRules($arrayItemTokens, null, $missingSymbols)
                : null;

            return sprintf('Array<%s>', $itemType ?? 'unknown');
        }

        if ($this->hasRule($names, self::BOOLEAN_RULES)) {
            return 'boolean';
        }

        if ($this->hasRule($names, self::NUMBER_RULES)) {
            return 'number';
        }

        if ($this->hasRule($names, self::FILE_RULES)) {
            return 'File';
        }

        if ($this->hasRule($names, self::JSON_RULES)) {
            return 'Record<string, unknown>';
        }

        if ($this->hasRule($names, self::STRING_RULES)) {
            return 'string';
        }

        return null;
    }

    private function typeToTypeScript(Type $type, MissingSymbolsCollection $missingSymbols): string
    {
        $transpiler = new TranspileTypeToTypeScriptAction(
            $missingSymbols,
            $this->config->shouldConsiderNullAsOptional()
        );

        return $transpiler->execute($type);
    }

    private function applyNullability(string $type, bool $nullable): string
    {
        if (! $nullable || $this->config->shouldConsiderNullAsOptional()) {
            return $type;
        }

        if (str_contains($type, 'null')) {
            return $type;
        }

        return $type.' | null';
    }

    private function isNullableType(Type $type): bool
    {
        if ($type instanceof Null_ || $type instanceof Nullable) {
            return true;
        }

        if (! $type instanceof Compound) {
            return false;
        }

        foreach ($type as $inner) {
            if ($this->isNullableType($inner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string|object>  $tokens
     */
    private function enumTypeFromRules(array $tokens, MissingSymbolsCollection $missingSymbols): ?string
    {
        foreach ($tokens as $token) {
            if ($token instanceof LaravelEnumRule) {
                $enum = $this->readRuleProperty($token, 'type');

                if (is_string($enum) && $enum !== '') {
                    return $missingSymbols->add(mb_ltrim($enum, '\\'));
                }
            }

            if ($token instanceof SpatieEnumRule) {
                $enum = $this->readRuleProperty($token, 'enum');

                if (is_string($enum) && $enum !== '') {
                    return $missingSymbols->add(mb_ltrim($enum, '\\'));
                }
            }

            if (is_string($token)) {
                [$name, $parameters] = $this->parseRuleString($token);

                if ($name === 'enum' && $parameters !== null) {
                    $enum = mb_trim(explode(',', $parameters)[0]);

                    if ($enum !== '') {
                        return $missingSymbols->add(mb_ltrim($enum, '\\'));
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string|object>  $tokens
     */
    private function inTypeFromRules(array $tokens): ?string
    {
        foreach ($tokens as $token) {
            if (! is_string($token)) {
                continue;
            }

            [$name, $parameters] = $this->parseRuleString($token);
            if ($name !== 'in') {
                continue;
            }

            if ($parameters === null) {
                continue;
            }

            $values = $this->parseInValues($parameters);
            $literals = array_map($this->valueToLiteral(...), $values);
            $literals = array_filter($literals, static fn (?string $literal): bool => $literal !== null);

            if ($literals === []) {
                continue;
            }

            return implode(' | ', array_values(array_unique($literals)));
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function parseRuleString(string $rule): array
    {
        $parts = explode(':', $rule, 2);
        $name = mb_strtolower($parts[0]);

        return [$name, $parts[1] ?? null];
    }

    /**
     * @return array<int, string>
     */
    private function parseInValues(string $parameters): array
    {
        $values = str_getcsv($parameters, ',', '"', '\\');

        return array_map(trim(...), $values);
    }

    private function valueToLiteral(string $value): string
    {
        if ($value === '') {
            return "''";
        }

        $lowered = mb_strtolower($value);

        if ($lowered === 'true' || $lowered === 'false') {
            return $lowered;
        }

        if (is_numeric($value)) {
            return $value;
        }

        $escaped = str_replace("'", "\\'", $value);

        return sprintf("'%s'", $escaped);
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $matches
     */
    private function hasRule(array $names, array $matches): bool
    {
        return array_intersect($names, $matches) !== [];
    }

    private function formatPropertyName(string $name): string
    {
        if (preg_match('/^[A-Za-z_\\$][A-Za-z0-9_\\$]*$/', $name) === 1) {
            return $name;
        }

        return "'".str_replace("'", "\\'", $name)."'";
    }

    private function isArrayItemField(string $field): bool
    {
        return str_ends_with($field, '.*');
    }

    private function arrayParentField(string $field): string
    {
        return mb_substr($field, 0, -2);
    }

    private function readRuleProperty(object $rule, string $property): mixed
    {
        $reflection = new ReflectionObject($rule);

        if (! $reflection->hasProperty($property)) {
            return null;
        }

        $prop = $reflection->getProperty($property);

        return $prop->getValue($rule);
    }
}
