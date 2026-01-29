<?php

declare(strict_types=1);

namespace App\TypeScript\Transformers;

use Illuminate\Http\Resources\Json\ResourceCollection as LaravelResourceCollection;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\Transformers\Transformer;

final readonly class ResourceCollectionTransformer implements Transformer
{
    public function transform(ReflectionClass $class, string $name): ?TransformedType
    {
        if ($class->isSubclassOf(LaravelResourceCollection::class) === false || $class->isAbstract()) {
            return null;
        }

        $defaults = $class->getDefaultProperties();
        $collects = $defaults['collects'] ?? null;

        if (is_string($collects) && class_exists($collects)) {
            $ref = new ReflectionClass($collects);
            $short = $ref->getShortName();
            $itemTsType = $short !== '' ? $short : 'unknown';
        } else {
            $short = $class->getShortName();
            if (str_ends_with($short, 'Collection')) {
                $base = mb_substr($short, 0, -10);
                $itemTsType = $base.'Resource';
            } else {
                $itemTsType = 'unknown';
            }
        }

        $paginated = $this->paginatedShape($itemTsType);
        $array = sprintf('Array<%s>', $itemTsType);

        $rhs = sprintf('%s | %s', $array, $paginated);
        $alias = sprintf("\nexport type %sResponse = ApiResponse<%s>", $name, $name);

        return TransformedType::create(
            $class,
            $name,
            $rhs.$alias
        );
    }

    private function paginatedShape(string $itemType): string
    {
        $lines = [
            'data: Array<'.$itemType.'>;',
            'links: { first?: string; last?: string; prev?: string | null; next?: string | null; };',
            'meta: { current_page: number; from?: number | null; last_page: number; path: string; per_page: number; to?: number | null; total: number; };',
        ];

        return "{\n    ".implode("\n    ", $lines)."\n}";
    }
}
