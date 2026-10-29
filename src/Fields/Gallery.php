<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Fields\Concerns\HasImageVariants;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use LogicException;
use Override;

/**
 * Несколько картинок из файлового менеджера с ручным порядком (перетаскиванием).
 * Хранится JSON-массивом путей, поэтому колонке нужен каст `array` в модели
 * (`'photos' => 'array'`): `["uploads/clinic/1.jpg", "uploads/clinic/2.jpg"]`.
 *
 * ```php
 * Gallery::make('photos', 'Фото клиники')->directory('clinic')->max(20);
 * ```
 */
class Gallery extends FileField
{
    use HasImageVariants;

    /** Наибольшее число картинок или null — без ограничения. */
    protected ?int $max = 100;

    /** Сколько миниатюр показывать в ячейке списка. */
    private const int LIST_PREVIEWS = 3;

    /**
     * Наибольшее число картинок.
     *
     * ```php
     * Gallery::make('photos')->max(20);
     * ```
     */
    public function max(int $max): static
    {
        $this->max = max(1, $max);

        return $this;
    }

    /**
     * Наибольшее число картинок или null.
     */
    public function getMax(): ?int
    {
        return $this->max;
    }

    #[Override]
    public function onlyImages(): bool
    {
        return true;
    }

    #[Override]
    public function isMultiple(): bool
    {
        return true;
    }

    /**
     * Пути записи списком строк.
     *
     * @return list<string>
     */
    #[Override]
    public function hydrate(Model $record): array
    {
        return $this->paths($record->getAttribute($this->name));
    }

    /**
     * Пути картинок — каждый с новой строки (или через запятую).
     *
     * @return list<string>
     */
    #[Override]
    public function importValue(mixed $cell): array
    {
        return self::splitList(str_replace(["\r\n", "\r", ','], "\n", self::cellString($cell) ?? ''), "\n");
    }

    /**
     * Пути картинок — каждый с новой строки.
     */
    #[Override]
    public function exportValue(Model $record): ?string
    {
        return implode("\n", $this->hydrate($record)) ?: null;
    }

    /**
     * @return list<string>
     */
    #[Override]
    protected function formatDefault(mixed $value): array
    {
        return $this->paths($value);
    }

    /**
     * Пути без пустых и повторов, в порядке формы.
     *
     * @return list<string>
     */
    #[Override]
    public function dehydrate(mixed $value): array
    {
        return array_values(array_unique(array_filter($this->paths($value), static fn (string $path): bool => $path !== '')));
    }

    /**
     * Пишет массив; модель должна приводить колонку к массиву.
     *
     * @throws LogicException у модели нет каста для колонки
     */
    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        if (! $record->hasCast($this->name)) {
            throw new LogicException(__('fastleo::exceptions.field_needs_array_cast', ['field' => $this->name, 'model' => $record::class], Fastleo::LOCALE));
        }

        parent::fill($record, $value);
    }

    /**
     * Новые картинки галереи → варианты для сайта (если заданы `variants()`).
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        if (! $record->wasRecentlyCreated && ! $record->wasChanged($this->name)) {
            return;
        }

        $previous = $record->wasRecentlyCreated ? [] : self::paths($record->getPrevious()[$this->name] ?? null);

        $this->queueVariants(array_values(array_diff(self::paths($value), $previous)));
    }

    #[Override]
    protected function typeRules(): array
    {
        return ['array', ...($this->max !== null ? ['max:'.$this->max] : [])];
    }

    #[Override]
    protected function nestedRules(): array
    {
        return ['*' => ['nullable', 'string', $this->pathRule()]];
    }

    /**
     * На просмотре — все миниатюры.
     */
    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        $paths = $this->paths($value);

        return $paths === [] ? null : $this->previews($paths);
    }

    /**
     * В списке — первые миниатюры и общее число.
     */
    #[Override]
    public function listDisplay(Model $record): string|Htmlable|null
    {
        $paths = $this->paths($record->getAttribute($this->name));

        if ($paths === [] || $this->formatUsing instanceof \Closure) {
            return $this->display($record);
        }

        $count = count($paths) > self::LIST_PREVIEWS
            ? '<span class="text-xs text-ink-2">'.e(__('fastleo::files.gallery_count', ['count' => count($paths)])).'</span>'
            : '';

        return new HtmlString('<span class="inline-flex items-center gap-1">'.$this->previews(array_slice($paths, 0, self::LIST_PREVIEWS))->toHtml().$count.'</span>');
    }

    /**
     * Миниатюры в ряд.
     *
     * @param  list<string>  $paths
     */
    private function previews(array $paths): HtmlString
    {
        $items = array_map(fn (string $path): string => $this->preview($path)->toHtml(), $paths);

        return new HtmlString('<span class="inline-flex flex-wrap gap-1">'.implode('', $items).'</span>');
    }

    /**
     * Значение (массив, JSON-строка, null) → список строк.
     *
     * @return list<string>
     */
    private function paths(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_iterable($value)) {
            return [];
        }

        $paths = [];

        foreach ($value as $path) {
            if (is_string($path)) {
                $paths[] = trim($path);
            }
        }

        return $paths;
    }
}
