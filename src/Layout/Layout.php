<?php

declare(strict_types=1);

namespace Fastleo\Layout;

use Fastleo\Fields\Field;
use Illuminate\Database\Eloquent\Model;

/**
 * Контейнер раскладки формы (вкладки, секция, сетка, боковая панель). Сам ничего не хранит —
 * только группирует поля и другие контейнеры.
 */
abstract class Layout
{
    /**
     * @param  list<Field|Layout>  $children  поля и вложенные контейнеры
     */
    public function __construct(protected array $children = []) {}

    /**
     * Вложенные поля и контейнеры.
     *
     * @return list<Field|Layout>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * Есть ли внутри хотя бы одно поле, видимое в форме для этой записи (null — создание).
     * Пустые контейнеры не выводятся.
     */
    public function hasVisibleFields(?Model $record): bool
    {
        return array_any($this->children, fn (\Fastleo\Fields\Field|Layout $child): bool => $child instanceof Field ? $child->isVisibleInForm($record) : $child->hasVisibleFields($record));
    }

    /**
     * Blade-шаблон контейнера.
     */
    abstract public function view(): string;
}
