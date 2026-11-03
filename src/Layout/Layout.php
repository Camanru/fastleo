<?php

declare(strict_types=1);

namespace Fastleo\Layout;

use Fastleo\Fields\Field;
use Fastleo\Related;
use Illuminate\Database\Eloquent\Model;

/**
 * Контейнер раскладки формы (вкладки, секция, сетка, боковая панель). Сам ничего не хранит —
 * только группирует поля и другие контейнеры.
 */
abstract class Layout
{
    /**
     * @param  list<Field|Layout|Related>  $children  поля, вложенные контейнеры и (только во вкладке) связанные таблицы
     */
    public function __construct(protected array $children = []) {}

    /**
     * Вложенные поля, контейнеры и связанные таблицы.
     *
     * @return list<Field|Layout|Related>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * Есть ли внутри хотя бы одно поле, видимое в форме для этой записи (null — создание), или связанная таблица,
     * которую можно показать. Пустые контейнеры не выводятся.
     */
    public function hasVisibleFields(?Model $record): bool
    {
        return array_any($this->children, fn (Field|Layout|Related $child): bool => match (true) {
            $child instanceof Field => $child->isVisibleInForm($record),
            $child instanceof Related => $child->isVisibleFor($record),
            default => $child->hasVisibleFields($record),
        });
    }

    /**
     * Blade-шаблон контейнера.
     */
    abstract public function view(): string;
}
