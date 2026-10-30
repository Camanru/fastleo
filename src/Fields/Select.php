<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use BackedEnum;
use Closure;
use Fastleo\Fastleo;
use Fastleo\Fields\Contracts\SearchesOptions;
use Fastleo\Support\OptionRule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Override;
use UnitEnum;

/**
 * Выбор из списка: один вариант или несколько (`multiple()`).
 *
 * Варианты — массив `значение => подпись`, PHP enum (подпись из метода `label()`, иначе имя) или замыкание.
 * Один вариант — колонка `string`/`integer` (или каст enum); несколько — колонка `json`
 * и каст `'array'` в модели. Сам проверяет, что выбраны только варианты из списка.
 *
 * ```php
 * Select::make('status', 'Статус')->options(['draft' => 'Черновик', 'published' => 'Опубликовано'])->required();
 * Select::make('status')->options(ArticleStatus::class);
 * Select::make('tags', 'Метки')->options(fn () => Tag::query()->pluck('name', 'id')->all())->multiple();
 * ```
 */
class Select extends Field implements SearchesOptions
{
    /**
     * Варианты: массив, класс enum или замыкание, возвращающее массив.
     *
     * @var array<array-key, string>|class-string<BackedEnum>|Closure(mixed): array<array-key, string>
     */
    protected array|string|Closure $options = [];

    /** Можно выбрать несколько вариантов. */
    protected bool $multiple = false;

    /**
     * Варианты, вычисленные один раз за запрос (по значению зависимости).
     *
     * @var array<string, array<array-key, string>>
     */
    private array $resolvedOptions = [];

    /**
     * Варианты списка.
     *
     * ```php
     * Select::make('status')->options(['draft' => 'Черновик', 'published' => 'Опубликовано']);
     * ```
     *
     * @param  array<array-key, string>|class-string<BackedEnum>|Closure(mixed): array<array-key, string>  $options
     */
    public function options(array|string|Closure $options): static
    {
        $this->options = $options;
        $this->resolvedOptions = [];

        return $this;
    }

    /**
     * Можно выбрать несколько вариантов (колонка json, каст `'array'`).
     *
     * ```php
     * Select::make('days')->options([...])->multiple();
     * ```
     */
    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    /**
     * Можно выбрать несколько вариантов.
     */
    #[Override]
    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * Варианты: значение → подпись.
     *
     * @return array<array-key, string>
     */
    public function getOptions(mixed $dependency = null): array
    {
        return $this->resolvedOptions[self::conditionValue($dependency)] ??= $this->resolveOptions($dependency);
    }

    /**
     * Зависимый список: замыкание вариантов получает значение другого поля формы (null, если не выбрано).
     * При смене поля варианты подгружаются с сервера.
     *
     * ```php
     * Select::make('model', 'Модель')->options(fn (?string $brand) => Car::models($brand))->dependsOn('brand');
     * ```
     *
     * @throws InvalidArgumentException имя поля не похоже на имя колонки
     */
    public function dependsOn(string $field): static
    {
        if (preg_match('/^[A-Za-z_]\w*$/', $field) !== 1) {
            throw new InvalidArgumentException(__('fastleo::exceptions.field_name', ['name' => $field], Fastleo::LOCALE));
        }

        $this->dependsOn = $field;
        $this->resolvedOptions = [];

        return $this;
    }

    /**
     * Варианты, подпись которых содержит строку поиска (для зависимого списка — все при пустом поиске).
     *
     * @return list<array{value: string, label: string}>
     */
    public function searchOptions(string $search, int $limit = 20, mixed $dependency = null): array
    {
        $options = [];

        foreach ($this->getOptions($dependency) as $value => $label) {
            if ($search === '' || mb_stripos($label, $search) !== false) {
                $options[] = ['value' => (string) $value, 'label' => $label];
            }
        }

        return $options;
    }

    /**
     * Варианты из массива, enum или замыкания.
     *
     * @return array<array-key, string>
     */
    private function resolveOptions(mixed $dependency): array
    {
        $options = $this->options;

        if ($options instanceof Closure) {
            return $options(self::dependencyValue($dependency));
        }

        if (is_string($options)) {
            $result = [];

            foreach ($options::cases() as $case) {
                $result[$case->value] = $this->enumLabel($case);
            }

            return $result;
        }

        return $options;
    }

    /**
     * Значение для формы: строка (или список строк) — enum превращается в его значение.
     */
    #[Override]
    public function hydrate(Model $record): mixed
    {
        $value = $record->getAttribute($this->name);

        if ($this->multiple) {
            return array_map($this->scalar(...), is_iterable($value) ? [...$value] : []);
        }

        return $this->scalar($value);
    }

    /**
     * Один вариант — значение или null; несколько — список без пустых.
     */
    #[Override]
    public function dehydrate(mixed $value): mixed
    {
        if ($this->multiple) {
            return array_values(array_filter(is_array($value) ? $value : [], fn (mixed $item): bool => $item !== null && $item !== ''));
        }

        return parent::dehydrate($value);
    }

    /**
     * Значение по умолчанию: enum → его значение.
     */
    #[Override]
    protected function formatDefault(mixed $value): mixed
    {
        return is_array($value) ? array_map(self::scalar(...), $value) : ($value === null ? null : self::scalar($value));
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        if ($this->multiple) {
            return ['array'];
        }

        return [$this->dependentRule()];
    }

    /**
     * Правила; сохранённое значение записи проходит проверку, даже если его нет среди вариантов (для зависимого списка —
     * пока зависимость не изменилась). Новое значение вне вариантов отклоняется.
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);

        if (! $record instanceof Model || ! $record->exists) {
            return $rules;
        }

        foreach ([$this->name, $this->name.'.*'] as $key) {
            $rules[$key] = array_map(fn (mixed $rule): mixed => $rule instanceof OptionRule ? $this->dependentRule($record) : $rule, $rules[$key] ?? []);
        }

        return $rules;
    }

    /**
     * Сохранённые значения записи: остаются выбранными в форме и проходят проверку, даже если сейчас вне вариантов
     * (старые данные, вариант убрали из списка, у зависимого списка поменялась зависимость). Админ их не теряет,
     * пока сам не выберет другое. У подполей блоков и вложенных записей список пуст: их правила строятся без записи.
     *
     * @return list<string>
     */
    public function savedValues(?Model $record): array
    {
        // Подполя блоков и вложенных записей проверяются без записи: сохранённое значение там не принимается.
        if ($this->rulePrefix !== '' || ! $record instanceof Model || ! $record->exists) {
            return [];
        }

        $value = $record->getOriginal($this->name);
        $values = array_map(self::scalar(...), is_array($value) ? $value : [$value]);

        return array_values(array_filter($values, fn (mixed $item): bool => is_string($item) && $item !== '' && ! $this->isEmptyValue($item)));
    }

    /**
     * @return array<string, list<mixed>>
     */
    #[Override]
    protected function nestedRules(): array
    {
        if (! $this->multiple) {
            return [];
        }

        return ['*' => ['distinct', $this->dependentRule()]];
    }

    /**
     * Подписи выбранных вариантов через запятую.
     */
    /**
     * Подписи — из вариантов для значения зависимости этой записи.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        if ($this->dependsOn === null || $this->formatUsing instanceof Closure) {
            return parent::display($record);
        }

        return $this->labels($record->getAttribute($this->name), $this->getOptions(self::dependencyValue($record->getAttribute($this->dependsOn))));
    }

    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        return $this->labels($value, $this->getOptions());
    }

    /**
     * Подпись или значение варианта (без учёта регистра) → значение; несколько — через запятую.
     * Неизвестное остаётся как есть — его отклонит проверка.
     *
     * @return string|list<string>|null
     */
    #[Override]
    public function importValue(mixed $cell): string|array|null
    {
        $value = self::cellString($cell);
        $options = $this->dependsOn === null ? $this->getOptions() : [];
        $byLabel = [];

        foreach ($options as $option => $label) {
            $byLabel[mb_strtolower($label)] = (string) $option;
            $byLabel[mb_strtolower((string) $option)] = (string) $option;
        }

        $map = fn (string $item): string => $byLabel[mb_strtolower($item)] ?? $item;

        if ($this->multiple) {
            return array_map($map, self::splitList($value, ','));
        }

        // Подпись пустого варианта (placeholder) — «не выбрано», запишется emptyValue().
        if ($value === null || ($this->hasEmptyValue() && $this->placeholder !== null && mb_strtolower($value) === mb_strtolower($this->placeholder))) {
            return null;
        }

        return $map($value);
    }

    /**
     * Подписи вариантов через запятую (для зависимого списка — из вариантов значения зависимости записи).
     */
    #[Override]
    public function exportValue(Model $record): ?string
    {
        $dependency = $this->dependsOn === null ? null : self::dependencyValue($record->getAttribute($this->dependsOn));

        return $this->labels($record->getAttribute($this->name), $this->getOptions($dependency));
    }

    /**
     * Правило «вариант из списка для значения зависимости в этой же форме».
     */
    private function dependentRule(?Model $record = null): OptionRule
    {
        $saved = $this->savedValues($record);

        return new OptionRule(
            fn (mixed $value, array $data): bool => is_scalar($value) && (
                (! $this->required && $this->isEmptyValue($value))
                || array_key_exists((string) $value, $this->getOptions($this->dependencyFrom($data)))
                || (in_array((string) $value, $saved, true) && $this->dependencyUnchanged($record, $data))
            ),
            'in',
            $this->dependsOn === null ? 'in:'.implode(',', array_map(fn (int|string $key): string => '"'.$key.'"', array_keys($this->getOptions()))) : null,
        );
    }

    /**
     * Подписи выбранных значений через запятую.
     *
     * @param  array<array-key, string>  $options
     */
    private function labels(mixed $value, array $options): ?string
    {
        $values = $this->multiple ? (is_iterable($value) ? [...$value] : []) : [$value];
        $labels = [];

        foreach ($values as $item) {
            $key = $this->scalar($item);

            if ($key !== null && $key !== '' && ! $this->isEmptyValue($key)) {
                $labels[] = $options[$key] ?? $key;
            }
        }

        return $labels === [] ? null : implode(', ', $labels);
    }

    /**
     * Значение варианта строкой: enum — его value, число — строкой.
     */
    private function scalar(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Подпись варианта enum: метод `label()`, если есть, иначе имя.
     */
    private function enumLabel(BackedEnum $case): string
    {
        if (method_exists($case, 'label')) {
            $label = $case->label();

            if (is_string($label)) {
                return $label;
            }
        }

        return $case->name;
    }
}
