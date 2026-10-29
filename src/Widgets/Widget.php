<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Closure;
use Fastleo\Fastleo;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Виджет дашборда: карточка с данными. Наследники — `Stat`, `Chart`, `Table`, `Sections`, `ServerStatus`;
 * свой виджет — класс с методами `view()` и `payload()`.
 *
 * Виджет, который упал при подготовке данных, показывает «Не удалось загрузить», а дашборд работает
 * (исключение попадает в лог).
 *
 * ```php
 * Stat::make('Заявки')->value(fn () => Lead::count())->columnSpan(2)->visible(fn ($user) => $user->is_owner);
 * ```
 */
abstract class Widget
{
    /** Ширина в колонках сетки (1–4). */
    protected int $span = 1;

    /** @var bool|Closure(?Authenticatable): bool */
    protected bool|Closure $visible = true;

    final public function __construct(protected string $title = '') {}

    /**
     * Создать виджет с заголовком.
     */
    public static function make(string $title = ''): static
    {
        return new static($title);
    }

    /**
     * Ширина в колонках сетки из четырёх: 1 — четверть, 4 — вся строка. На узких экранах виджеты идут в столбик.
     */
    public function columnSpan(int $span): static
    {
        $this->span = max(1, min(4, $span));

        return $this;
    }

    /**
     * Показывать виджет не всем: замыкание получает администратора и возвращает `true`/`false`.
     *
     * @param  bool|Closure(?Authenticatable): bool  $visible
     */
    public function visible(bool|Closure $visible = true): static
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Виджет показывается текущему администратору.
     */
    public function isVisible(): bool
    {
        return $this->visible instanceof Closure ? (bool) ($this->visible)(Fastleo::guard()->user()) : $this->visible;
    }

    /**
     * Ширина в колонках.
     */
    public function getSpan(): int
    {
        return $this->span;
    }

    /**
     * Заголовок карточки.
     */
    public function getTitle(): string
    {
        return $this->title !== '' ? $this->title : $this->defaultTitle();
    }

    /**
     * Blade-шаблон карточки.
     */
    abstract public function view(): string;

    /**
     * Данные для шаблона. Любое исключение превращается в «Не удалось загрузить».
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * Данные для шаблона: результат `payload()` или признак ошибки.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        try {
            return [...$this->payload(), 'failed' => false];
        } catch (Throwable $exception) {
            report($exception);

            return ['failed' => true];
        }
    }

    /**
     * Заголовок, когда он не задан.
     */
    protected function defaultTitle(): string
    {
        return '';
    }
}
