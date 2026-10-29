<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

/**
 * Метки свободным вводом: Enter или запятая добавляет метку, × — убирает. Хранится JSON-массивом строк,
 * поэтому колонке нужен каст `array` в модели (`'keywords' => 'array'`). Повторы убираются сами.
 *
 * ```php
 * Tags::make('keywords', 'Ключевые слова')->suggestions(['акция', 'новинка'])->maxTagLength(30);
 * ```
 */
class Tags extends Field
{
    /**
     * Подсказки при вводе.
     *
     * @var list<string>
     */
    protected array $suggestions = [];

    /** Наибольшая длина одной метки. */
    protected int $maxTagLength = 50;

    /** Наибольшее число меток в поле. */
    public const int MAX_TAGS = 100;

    /**
     * Варианты-подсказки при вводе (можно ввести и другие).
     *
     * ```php
     * Tags::make('keywords')->suggestions(['акция', 'новинка']);
     * ```
     *
     * @param  list<string>  $suggestions
     */
    public function suggestions(array $suggestions): static
    {
        $this->suggestions = $suggestions;

        return $this;
    }

    /**
     * Наибольшая длина одной метки (по умолчанию 50).
     *
     * ```php
     * Tags::make('keywords')->maxTagLength(30);
     * ```
     */
    public function maxTagLength(int $length): static
    {
        $this->maxTagLength = max(1, $length);

        return $this;
    }

    /**
     * Подсказки при вводе.
     *
     * @return list<string>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /**
     * Наибольшая длина метки.
     */
    public function getMaxTagLength(): int
    {
        return $this->maxTagLength;
    }

    /**
     * Метки записи списком строк.
     *
     * @return list<string>
     */
    #[Override]
    public function hydrate(Model $record): array
    {
        return $this->strings($record->getAttribute($this->name));
    }

    /**
     * Значение по умолчанию списком строк.
     *
     * @return list<string>
     */
    #[Override]
    protected function formatDefault(mixed $value): array
    {
        return $this->strings($value);
    }

    /**
     * Метки без пустых и повторов, обрезанные по краям.
     *
     * @return list<string>
     */
    #[Override]
    public function dehydrate(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            array_map(trim(...), $this->strings($value)),
            fn (string $tag): bool => $tag !== '',
        )));
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

    #[Override]
    protected function typeRules(): array
    {
        return ['array', 'max:'.self::MAX_TAGS];
    }

    #[Override]
    public function isMultiple(): bool
    {
        return true;
    }

    #[Override]
    protected function nestedRules(): array
    {
        return ['*' => ['nullable', 'string', 'max:'.$this->maxTagLength]];
    }

    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        $tags = $this->strings($value);

        return $tags === [] ? null : implode(', ', $tags);
    }

    /**
     * Значение (массив, JSON-строка, null) → список строк.
     *
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_iterable($value)) {
            return [];
        }

        $tags = [];

        foreach ($value as $tag) {
            if (is_scalar($tag)) {
                $tags[] = (string) $tag;
            }
        }

        return $tags;
    }
}
