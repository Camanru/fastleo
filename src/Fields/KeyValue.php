<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Support\ValidationMessages;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use LogicException;
use Override;

/**
 * Пары «название — значение» в одной JSON-колонке: характеристики товара, свойства, параметры.
 *
 * Колонка `json`, в модели — каст `'array'`. Хранится объект `{"Вес": "1,5 кг", "Цвет": "белый"}`;
 * названия обязательны и не повторяются, пустые строки отбрасываются. Порядок пар — как в форме
 * (в PostgreSQL `jsonb` порядок ключей не хранится — используйте колонку `json`).
 *
 * ```php
 * KeyValue::make('specs', 'Характеристики')->keyLabel('Параметр')->valueLabel('Значение');
 * ```
 */
class KeyValue extends Field
{
    /** Не показывается в таблице списка. */
    protected bool $showInList = false;

    /** Не выгружается в Excel/CSV. */
    protected bool $exportable = false;

    /** Подпись столбца названий. */
    protected ?string $keyLabel = null;

    /** Подпись столбца значений. */
    protected ?string $valueLabel = null;

    /** Наибольшее число пар. */
    protected int $maxPairs = 100;

    /**
     * Подпись столбца названий.
     *
     * ```php
     * KeyValue::make('specs')->keyLabel('Параметр');
     * ```
     */
    public function keyLabel(string $label): static
    {
        $this->keyLabel = $label;

        return $this;
    }

    /**
     * Подпись столбца значений.
     *
     * ```php
     * KeyValue::make('specs')->valueLabel('Значение');
     * ```
     */
    public function valueLabel(string $label): static
    {
        $this->valueLabel = $label;

        return $this;
    }

    /**
     * Не больше стольких пар (по умолчанию 100).
     *
     * ```php
     * KeyValue::make('specs')->max(30);
     * ```
     */
    public function max(int $pairs): static
    {
        $this->maxPairs = max(1, $pairs);

        return $this;
    }

    /**
     * Подпись столбца названий.
     */
    public function getKeyLabel(): string
    {
        return $this->keyLabel ?? (string) __('fastleo::resources.key_value_key', locale: Fastleo::LOCALE);
    }

    /**
     * Подпись столбца значений.
     */
    public function getValueLabel(): string
    {
        return $this->valueLabel ?? (string) __('fastleo::resources.key_value_value', locale: Fastleo::LOCALE);
    }

    /**
     * Наибольшее число пар.
     */
    public function getMax(): int
    {
        return $this->maxPairs;
    }

    #[Override]
    public function isMultiple(): bool
    {
        return true;
    }

    /**
     * Пары для формы: список `['key' => …, 'value' => …]`.
     *
     * @return list<array{key: string, value: string}>
     */
    #[Override]
    public function hydrate(Model $record): array
    {
        return $this->pairs($record->getAttribute($this->name));
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    #[Override]
    protected function formatDefault(mixed $value): array
    {
        return $this->pairs($value);
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['array', 'max:'.$this->maxPairs];
    }

    /**
     * Название — строка до 255 символов, не повторяется; значение — строка до 65 535.
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    protected function nestedRules(): array
    {
        return [
            '*' => ['array'],
            '*.key' => ['required_with:'.$this->rulePrefix.$this->name.'.*.value', 'nullable', 'string', 'max:255', $this->uniqueKeyRule()],
            '*.value' => ['nullable', 'string', 'max:65535'],
        ];
    }

    /**
     * Название не повторяется: «Вес» и «вес » — одно и то же (при записи пробелы по краям убираются и пары слились бы).
     */
    private function uniqueKeyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail, Validator $validator): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            // «specs.2.key» → пары лежат в «specs».
            $pairs = Arr::get($validator->getData(), Str::beforeLast(Str::beforeLast($attribute, '.'), '.'));
            $same = 0;

            foreach (is_array($pairs) ? $pairs : [] as $pair) {
                $key = is_array($pair) ? ($pair['key'] ?? null) : null;

                if (is_string($key) && mb_strtolower(trim($key)) === mb_strtolower(trim($value))) {
                    $same++;
                }
            }

            if ($same > 1) {
                $fail(ValidationMessages::line('distinct'));
            }
        };
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function validationAttributes(): array
    {
        return [
            ...parent::validationAttributes(),
            $this->name.'.*.key' => $this->getLabel().': '.mb_strtolower($this->getKeyLabel()),
            $this->name.'.*.value' => $this->getLabel().': '.mb_strtolower($this->getValueLabel()),
        ];
    }

    /**
     * Пары из запроса → объект `название => значение` (пустые строки отброшены).
     *
     * @return array<string, string|null>
     */
    #[Override]
    public function dehydrate(mixed $value): array
    {
        $result = [];

        foreach (is_array($value) ? $value : [] as $pair) {
            if (! is_array($pair)) {
                continue;
            }

            $key = is_string($pair['key'] ?? null) ? trim($pair['key']) : '';
            $item = is_string($pair['value'] ?? null) ? trim($pair['value']) : '';

            if ($key !== '') {
                $result[$key] = $item === '' ? null : $item;
            }
        }

        return $result;
    }

    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        if (! $record->hasCast($this->name)) {
            throw new LogicException(__('fastleo::exceptions.field_needs_array_cast', ['field' => $this->name, 'model' => $record::class], Fastleo::LOCALE));
        }

        // Названия «0», «1»… сохранились бы списком, а не объектом.
        parent::fill($record, is_array($value) && $value !== [] && array_is_list($value) ? (object) $value : $value);
    }

    /**
     * На просмотре — список «название: значение».
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        if ($this->formatUsing instanceof Closure) {
            return parent::display($record);
        }

        $pairs = $this->hydrate($record);

        if ($pairs === []) {
            return null;
        }

        $rows = array_map(fn (array $pair): string => '<div><dt class="inline text-ink-2">'.e($pair['key']).':</dt> <dd class="inline">'.e($pair['value']).'</dd></div>', $pairs);

        return new HtmlString('<dl class="flex flex-col gap-1 whitespace-normal">'.implode('', $rows).'</dl>');
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::fields.key-value';
    }

    /**
     * Сохранённое значение (объект или json-строка) → пары.
     *
     * @return list<array{key: string, value: string}>
     */
    private function pairs(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $pairs = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            $pairs[] = ['key' => (string) $key, 'value' => is_scalar($item) ? (string) $item : ''];
        }

        return $pairs;
    }

    /**
     * Пустое значение здесь имеет свой смысл (галочка, пароль, файл, список, вложенные записи) — `emptyValue()` недоступен.
     */
    #[Override]
    protected function supportsEmptyValue(): bool
    {
        return false;
    }
}
