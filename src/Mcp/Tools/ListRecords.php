<?php

declare(strict_types=1);

namespace Fastleo\Mcp\Tools;

use Fastleo\Mcp\RecordData;
use Fastleo\Support\ResourceQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

/**
 * Записи раздела постранично: поиск, фильтры, сортировка — как в списке админки.
 */
#[Description('Записи раздела постранично. Поиск q идёт по полям searchable, filters — по фильтрам раздела (имена из describe-resource), sort — по полям sortable.')]
#[IsReadOnly]
final class ListRecords extends ResourceTool
{
    /** Наибольшее число записей на странице. */
    private const int MAX_PER_PAGE = 100;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Адрес раздела из list-resources.')->required(),
            'q' => $schema->string()->description('Строка поиска.'),
            'filters' => $schema->object()->description('Значения фильтров по именам: {"status": "published"}.'),
            'sort' => $schema->string()->description('Поле сортировки (sortable).'),
            'direction' => $schema->string()->description('asc или desc.'),
            'page' => $schema->integer()->description('Номер страницы, с 1.'),
            'per_page' => $schema->integer()->description('Записей на странице, до '.self::MAX_PER_PAGE.' (по умолчанию — как в админке).'),
        ];
    }

    #[Override]
    protected function run(Request $request): Response
    {
        $resource = $this->resource($request, 'viewAny');
        $data = $request->validate([
            'q' => ['nullable', 'string'],
            'filters' => ['nullable', 'array'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $list = new ResourceQuery($resource, HttpRequest::create('/', 'GET', array_filter([
            'q' => $request->get('q'),
            'filter' => $request->get('filters'),
            'sort' => $request->get('sort'),
            'direction' => $request->get('direction'),
        ], fn (mixed $value): bool => $value !== null)), withDefaults: false);

        $perPage = min(self::MAX_PER_PAGE, $this->positive($data['per_page'] ?? null, $resource::perPage()));
        $page = $this->positive($data['page'] ?? null, 1);
        $query = $list->query();
        $total = $query->toBase()->getCountForPagination();

        return Response::json([
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'records' => $query->forPage($page, $perPage)->get()->map(fn (Model $record): array => RecordData::row($resource, $record))->all(),
        ]);
    }

    /**
     * Целое не меньше 1 или значение по умолчанию.
     */
    private function positive(mixed $value, int $default): int
    {
        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}
