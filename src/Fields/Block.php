<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Layout\Layout;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Тип блока конструктора `Blocks`: имя (оно же шаблон блока на сайте), подпись, иконка и поля.
 *
 * ```php
 * Block::make('quote', 'Цитата')->icon('quote')->fields([
 *     Textarea::make('text', 'Текст')->required(),
 *     Text::make('author', 'Автор'),
 * ]);
 * ```
 */
final class Block
{
    /** Иконка Lucide в меню «Добавить блок» и в заголовке блока. */
    private string $icon = 'square';

    /**
     * Поля и раскладка блока.
     *
     * @var list<Field|Layout>
     */
    private array $items = [];

    /**
     * @param  string  $type  имя типа: латиница в нижнем регистре, цифры, `_` (`text`, `photo_gallery`)
     *
     * @throws InvalidArgumentException недопустимое имя типа
     */
    public function __construct(public readonly string $type, private readonly ?string $label = null)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,49}$/', $type) !== 1 || $type === 'type') {
            throw new InvalidArgumentException(__('fastleo::exceptions.block_type', ['type' => $type], Fastleo::LOCALE));
        }
    }

    /**
     * Тип блока.
     *
     * ```php
     * Block::make('text', 'Текст');
     * ```
     */
    public static function make(string $type, ?string $label = null): self
    {
        return new self($type, $label);
    }

    /**
     * Иконка Lucide.
     *
     * ```php
     * Block::make('gallery')->icon('images');
     * ```
     */
    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Поля блока: простые поля, а также `RichText`, `Image`, `File`, `Gallery`.
     *
     * ```php
     * Block::make('text')->fields([RichText::make('body')]);
     * ```
     *
     * @param  list<Field|Layout>  $fields
     */
    public function fields(array $fields): self
    {
        $this->items = $fields;

        return $this;
    }

    /**
     * Подпись типа.
     */
    public function getLabel(): string
    {
        return $this->label ?? Str::headline($this->type);
    }

    /**
     * Иконка типа.
     */
    public function getIcon(): string
    {
        return $this->icon;
    }

    /**
     * Поля и раскладка блока.
     *
     * @return list<Field|Layout>
     */
    public function getItems(): array
    {
        return $this->items;
    }
}
