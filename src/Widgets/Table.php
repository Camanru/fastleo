<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

/**
 * Последние записи раздела: первые столбцы списка ресурса, новые сверху, со ссылкой на каждую запись.
 * Показывается только тем, кому по Policy можно открывать раздел.
 *
 * ```php
 * Table::make('Последние заявки')->resource(LeadResource::class)->limit(5)->columnSpan(2);
 * ```
 */
final class Table extends Widget
{
    /** @var class-string<Resource>|null */
    private ?string $resource = null;

    private int $limit = 5;

    /**
     * Раздел, записи которого показывает таблица.
     *
     * @param  class-string<Resource>  $resource
     */
    public function resource(string $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Сколько записей показать (1–20).
     */
    public function limit(int $limit): static
    {
        $this->limit = max(1, min(20, $limit));

        return $this;
    }

    #[Override]
    public function isVisible(): bool
    {
        $resource = $this->instance();

        return parent::isVisible() && $resource instanceof Resource && Authorization::allows('viewAny', $resource);
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::widgets.table';
    }

    #[Override]
    protected function defaultTitle(): string
    {
        $class = $this->resource;

        return $class === null ? '' : $class::label();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function payload(): array
    {
        $resource = $this->instance() ?? throw new LogicException('В виджете Table не указан раздел: ->resource(DoctorResource::class).');
        $fields = array_slice($resource->listFields(), 0, 4);
        $eager = array_values(array_unique(array_merge(...array_map(fn (Field $field): array => $field->eagerLoad(), $fields))));
        $key = $resource->newModel()->getKeyName();

        $records = $resource->newQuery()->with($eager)->orderByDesc($key)->limit($this->limit)->get();

        return [
            'fields' => $fields,
            'rows' => $records->map(fn (Model $record): array => [
                'cells' => array_map(fn (Field $field): string|\Illuminate\Contracts\Support\Htmlable|null => $field->listDisplay($record), $fields),
                'url' => Authorization::allows('view', $resource, $record) ? route('fastleo.resources.show', [$resource::slug(), $record->getKey()]) : null,
            ])->all(),
            'allUrl' => route('fastleo.resources.index', $resource::slug()),
        ];
    }

    /**
     * Объект раздела из реестра.
     */
    private function instance(): ?Resource
    {
        if ($this->resource === null) {
            return null;
        }

        return Fastleo::resources()->find($this->resource::slug());
    }
}
