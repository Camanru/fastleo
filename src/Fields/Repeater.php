<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fields\Concerns\StoresJsonItems;
use Fastleo\Layout\Layout;
use Fastleo\Support\FormSchema;
use Fastleo\Support\JsonItem;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Override;

/**
 * Повторяющаяся группа полей в одной JSON-колонке: вопросы и ответы, этапы работ, строки прайса
 * без отдельной таблицы. Элементы добавляются, удаляются и перетаскиваются; порядок сохраняется.
 *
 * Колонка `json`, в модели — каст `'array'` (без него — понятная ошибка). Хранится список объектов:
 * `[{"question": "Как записаться?", "answer": "По телефону"}, ...]`. В элемент попадают только
 * описанные подполя; пустой новый элемент не сохраняется.
 *
 * ```php
 * Repeater::make('faq', 'Вопросы и ответы')
 *     ->fields([
 *         Text::make('question', 'Вопрос')->required(),
 *         Textarea::make('answer', 'Ответ'),
 *     ])
 *     ->max(20);
 * ```
 */
class Repeater extends Field
{
    use StoresJsonItems;

    /** Повторитель не показывается в таблице списка. */
    protected bool $showInList = false;

    /** Не выгружается в Excel/CSV. */
    protected bool $exportable = false;

    /**
     * Подполя и раскладка элемента.
     *
     * @var list<Field|Layout>
     */
    protected array $items = [];

    /** Наименьшее число элементов. */
    protected ?int $minItems = null;

    /** Наибольшее число элементов. */
    protected int $maxItems = 100;

    /** Схема элемента. */
    private ?FormSchema $schema = null;

    /**
     * Подполя элемента: простые поля (текст, число, дата, список, галочка, картинка, файл).
     *
     * ```php
     * Repeater::make('steps')->fields([Text::make('title')->required(), Image::make('icon')]);
     * ```
     *
     * @param  list<Field|Layout>  $fields
     */
    public function fields(array $fields): static
    {
        $this->items = $fields;
        $this->schema = null;

        return $this;
    }

    /**
     * Не меньше стольких элементов.
     *
     * ```php
     * Repeater::make('faq')->min(1);
     * ```
     */
    public function min(int $items): static
    {
        $this->minItems = max(0, $items);

        return $this;
    }

    /**
     * Не больше стольких элементов (по умолчанию 100).
     *
     * ```php
     * Repeater::make('faq')->max(10);
     * ```
     */
    public function max(int $items): static
    {
        $this->maxItems = max(1, $items);

        return $this;
    }

    /**
     * Наименьшее число элементов или null.
     */
    public function getMin(): ?int
    {
        return $this->minItems;
    }

    /**
     * Наибольшее число элементов.
     */
    public function getMax(): int
    {
        return $this->maxItems;
    }

    /**
     * Схема элемента (правила подполей с префиксом `faq.*.`).
     */
    public function schema(): FormSchema
    {
        return $this->schema ??= $this->itemSchema($this->items, $this->name.'.*.');
    }

    /**
     * Записываемые подполя элемента.
     *
     * @return list<Field>
     */
    public function itemFields(): array
    {
        return self::writableItemFields($this->schema());
    }

    #[Override]
    public function isMultiple(): bool
    {
        return true;
    }

    /**
     * Элементы для формы — модели-обёртки с сохранёнными значениями.
     *
     * @return Collection<int, JsonItem>
     */
    #[Override]
    public function hydrate(Model $record): Collection
    {
        return new Collection(array_map(JsonItem::from(...), self::storedItems($record->getAttribute($this->name))));
    }

    /**
     * @return Collection<int, JsonItem>
     */
    #[Override]
    protected function formatDefault(mixed $value): Collection
    {
        return new Collection(array_map(JsonItem::from(...), self::storedItems($value)));
    }

    /**
     * Правила поля и подполей (`faq.*.question`).
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);
        $rules[$this->name.'.*'] = ['array'];

        foreach ($this->itemFields() as $field) {
            foreach ($field->getRules(null) as $key => $fieldRules) {
                $rules[$this->name.'.*.'.$key] = $fieldRules;
            }
        }

        return $rules;
    }

    #[Override]
    protected function presenceRule(?Model $record): string
    {
        return $this->required || ($this->minItems ?? 0) > 0 ? 'required' : 'nullable';
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['array', 'max:'.$this->maxItems, ...($this->minItems !== null ? ['min:'.$this->minItems] : [])];
    }

    /**
     * Подписи подполей для текстов ошибок.
     *
     * @return array<string, string>
     */
    #[Override]
    public function validationAttributes(): array
    {
        $attributes = parent::validationAttributes();

        foreach ($this->schema()->fields() as $field) {
            foreach ($field->validationAttributes() as $key => $label) {
                $attributes[$this->name.'.*.'.$key] = $label;
            }
        }

        return $attributes;
    }

    /**
     * Элементы из запроса в порядке формы: только описанные подполя, пустые новые элементы отброшены.
     *
     * @return list<array<string, mixed>>
     */
    #[Override]
    public function dehydrate(mixed $value): array
    {
        $fields = $this->itemFields();
        $items = [];

        foreach (is_array($value) ? $value : [] as $input) {
            if (! is_array($input)) {
                continue;
            }

            $item = self::dehydrateItem($fields, $input);

            if (! self::isEmptyItem($fields, $item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        $this->assertArrayCast($record);

        parent::fill($record, $value);
    }

    /**
     * Варианты новых картинок подполей Image.
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        if (! $record->wasRecentlyCreated && ! $record->wasChanged($this->name)) {
            return;
        }

        $previous = $record->wasRecentlyCreated ? [] : self::storedItems($record->getPrevious()[$this->name] ?? null);

        self::queueItemVariants($this->itemFields(), self::storedItems($value), $previous);
    }

    /**
     * На просмотре — таблица элементов.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        if ($this->formatUsing instanceof \Closure) {
            return parent::display($record);
        }

        $items = $this->hydrate($record);

        if ($items->isEmpty()) {
            return null;
        }

        return new HtmlString(view('fastleo::fields.repeater-show', [
            'items' => $items,
            'fields' => array_values(array_filter($this->schema()->fields(), fn (Field $field): bool => ! $field instanceof Hidden)),
        ])->render());
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
