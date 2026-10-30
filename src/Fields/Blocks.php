<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Fields\Concerns\StoresJsonItems;
use Fastleo\Support\FormSchema;
use Fastleo\Support\JsonItem;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use LogicException;
use Override;

/**
 * Конструктор страницы: блоки разных типов (текст, картинка, галерея, цитата…) в одной JSON-колонке.
 * Блоки добавляются из меню, удаляются, сворачиваются и перетаскиваются.
 *
 * Хранение — список `[{"type": "text", "data": {"body": "<p>…</p>"}}, …]`; на сайте блоки выводит
 * `<x-fastleo::blocks :blocks="$page->content" />` шаблонами `resources/views/blocks/{type}.blade.php`.
 * Колонка `json` (или `longText`), в модели — каст `'array'`.
 *
 * ```php
 * Blocks::make('content', 'Содержимое')->blocks([
 *     Block::make('text', 'Текст')->icon('text')->fields([RichText::make('body')->required()]),
 *     Block::make('image', 'Картинка')->icon('image')->fields([Image::make('src')->required(), Text::make('alt')]),
 *     Block::make('quote', 'Цитата')->icon('quote')->fields([Textarea::make('text')->required(), Text::make('author')]),
 * ]);
 * ```
 */
class Blocks extends Field
{
    use StoresJsonItems;

    /** Не показывается в таблице списка. */
    protected bool $showInList = false;

    /** Не выгружается в Excel/CSV. */
    protected bool $exportable = false;

    /**
     * Типы блоков по имени.
     *
     * @var array<string, Block>
     */
    protected array $types = [];

    /** Наименьшее число блоков. */
    protected ?int $minBlocks = null;

    /** Наибольшее число блоков. */
    protected int $maxBlocks = 100;

    /**
     * Схемы полей типов (по имени типа).
     *
     * @var array<string, FormSchema>
     */
    private array $schemas = [];

    /**
     * Типы блоков.
     *
     * ```php
     * Blocks::make('content')->blocks([Block::make('text')->fields([RichText::make('body')])]);
     * ```
     *
     * @param  list<Block>  $blocks
     *
     * @throws LogicException тип описан дважды
     */
    public function blocks(array $blocks): static
    {
        $this->types = [];
        $this->schemas = [];

        foreach ($blocks as $block) {
            if (isset($this->types[$block->type])) {
                throw new LogicException(__('fastleo::exceptions.block_duplicate', ['type' => $block->type, 'field' => $this->name], Fastleo::LOCALE));
            }

            $this->types[$block->type] = $block;
        }

        return $this;
    }

    /**
     * Не меньше стольких блоков.
     *
     * ```php
     * Blocks::make('content')->min(1);
     * ```
     */
    public function min(int $blocks): static
    {
        $this->minBlocks = max(0, $blocks);

        return $this;
    }

    /**
     * Не больше стольких блоков (по умолчанию 100).
     *
     * ```php
     * Blocks::make('content')->max(30);
     * ```
     */
    public function max(int $blocks): static
    {
        $this->maxBlocks = max(1, $blocks);

        return $this;
    }

    /**
     * Наименьшее число блоков или null.
     */
    public function getMin(): ?int
    {
        return $this->minBlocks;
    }

    /**
     * Наибольшее число блоков.
     */
    public function getMax(): int
    {
        return $this->maxBlocks;
    }

    /**
     * Типы блоков по имени.
     *
     * @return array<string, Block>
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * Схема полей типа (правила с префиксом `content.*.{тип}.`).
     *
     * @throws LogicException нет такого типа или в нём поле, которое там не работает
     */
    public function schema(string $type): FormSchema
    {
        $block = $this->types[$type] ?? throw new LogicException(__('fastleo::exceptions.block_type', ['type' => $type], Fastleo::LOCALE));

        return $this->schemas[$type] ??= $this->itemSchema($block->getItems(), $this->name.'.*.'.$type.'.');
    }

    /**
     * Записываемые поля типа.
     *
     * @return list<Field>
     */
    public function typeFields(string $type): array
    {
        return self::writableItemFields($this->schema($type));
    }

    #[Override]
    public function isMultiple(): bool
    {
        return true;
    }

    /**
     * Блоки для формы: тип и модель-обёртка с данными. Блоки неизвестного типа пропускаются.
     *
     * @return list<array{type: string, item: JsonItem}>
     */
    #[Override]
    public function hydrate(Model $record): array
    {
        return $this->formBlocks($record->getAttribute($this->name));
    }

    /**
     * @return list<array{type: string, item: JsonItem}>
     */
    #[Override]
    protected function formatDefault(mixed $value): array
    {
        return $this->formBlocks($value);
    }

    /**
     * Правила: тип из списка; поля типа — только у блоков этого типа (`content.*.text.body`).
     *
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);
        $rules[$this->name.'.*'] = ['array'];
        $rules[$this->name.'.*.type'] = ['required', 'string', Rule::in(array_keys($this->types))];

        foreach (array_keys($this->types) as $type) {
            $condition = 'exclude_unless:'.$this->rulePrefix.$this->name.'.*.type,'.$type;

            foreach ($this->typeFields($type) as $field) {
                foreach ($field->getRules(null) as $key => $fieldRules) {
                    $rules[$this->name.'.*.'.$type.'.'.$key] = [$condition, ...$fieldRules];
                }
            }
        }

        return $rules;
    }

    #[Override]
    protected function presenceRule(?Model $record): string
    {
        return $this->required || ($this->minBlocks ?? 0) > 0 ? 'required' : 'nullable';
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['array', 'max:'.$this->maxBlocks, ...($this->minBlocks !== null ? ['min:'.$this->minBlocks] : [])];
    }

    /**
     * Подписи полей типов для текстов ошибок.
     *
     * @return array<string, string>
     */
    #[Override]
    public function validationAttributes(): array
    {
        $attributes = parent::validationAttributes();

        foreach (array_keys($this->types) as $type) {
            foreach ($this->schema($type)->fields() as $field) {
                foreach ($field->validationAttributes() as $key => $label) {
                    $attributes[$this->name.'.*.'.$type.'.'.$key] = $label;
                }
            }
        }

        return $attributes;
    }

    /**
     * Блоки из запроса в порядке формы → `[{type, data}]` (только поля своего типа).
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    #[Override]
    public function dehydrate(mixed $value): array
    {
        $blocks = [];

        foreach (is_array($value) ? $value : [] as $input) {
            $type = is_array($input) && is_string($input['type'] ?? null) ? $input['type'] : null;

            if ($type === null || ! isset($this->types[$type])) {
                continue;
            }

            $data = is_array($input[$type] ?? null) ? $input[$type] : [];
            $blocks[] = ['type' => $type, 'data' => self::dehydrateItem($this->typeFields($type), $data)];
        }

        return $blocks;
    }

    #[Override]
    public function fill(Model $record, mixed $value): void
    {
        $this->assertArrayCast($record);

        parent::fill($record, $value);
    }

    /**
     * Варианты новых картинок полей Image и Gallery всех блоков.
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        if (! $record->wasRecentlyCreated && ! $record->wasChanged($this->name)) {
            return;
        }

        $current = self::storedItems($value);
        $previous = $record->wasRecentlyCreated ? [] : self::storedItems($record->getPrevious()[$this->name] ?? null);

        foreach (array_keys($this->types) as $type) {
            self::queueItemVariants($this->typeFields($type), $this->dataOf($current, $type), $this->dataOf($previous, $type));
        }
    }

    /**
     * На просмотре — блоки по порядку: подпись типа и значения полей.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        if ($this->formatUsing instanceof \Closure) {
            return parent::display($record);
        }

        $blocks = $this->hydrate($record);

        return $blocks === [] ? null : new HtmlString(view('fastleo::fields.blocks-show', ['field' => $this, 'blocks' => $blocks])->render());
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::fields.blocks';
    }

    /**
     * Сохранённое значение → блоки известных типов для формы.
     *
     * @return list<array{type: string, item: JsonItem}>
     */
    private function formBlocks(mixed $value): array
    {
        $blocks = [];

        foreach (self::storedItems($value) as $block) {
            $type = $block['type'] ?? null;

            if (is_string($type) && isset($this->types[$type])) {
                $blocks[] = ['type' => $type, 'item' => JsonItem::from(is_array($block['data'] ?? null) ? $block['data'] : [])];
            }
        }

        return $blocks;
    }

    /**
     * Данные блоков одного типа.
     *
     * @param  list<array<array-key, mixed>>  $blocks
     * @return list<array<array-key, mixed>>
     */
    private function dataOf(array $blocks, string $type): array
    {
        $data = [];

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === $type && is_array($block['data'] ?? null)) {
                $data[] = $block['data'];
            }
        }

        return $data;
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
