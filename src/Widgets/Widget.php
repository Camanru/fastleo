<?php

declare(strict_types=1);

namespace Fastleo\Widgets;

use Closure;
use Fastleo\Fastleo;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Throwable;

/**
 * Виджет дашборда: карточка с данными. Наследники — `Stat`, `Chart`, `Table`, `Sections`, `ServerStatus`;
 * свой виджет — класс с методами `view()` и `payload()`.
 *
 * Виджет, который упал при подготовке данных, показывает «Не удалось загрузить», а дашборд работает
 * (исключение попадает в лог).
 *
 * ```php
 * Stat::make('Заявки')->value(fn () => Lead::count())->columnSpan(2)->rowSpan(2)->visible(fn ($user) => $user->is_owner);
 * ```
 *
 * Размер задаёт сайт (`columnSpan()` — ширина, `rowSpan()` — высота), администратор может поменять его, порядок
 * и скрыть виджет кнопкой «Настроить» на дашборде. Настройки администратора хранятся по ключу виджета (`key()`).
 */
abstract class Widget
{
    /** Ширина в колонках сетки (1–4; на странице-отчёте с шестью колонками — до 6). */
    protected int $span = 1;

    /** Высота в строках сетки (1–4). */
    protected int $rows = 1;

    /** Постоянный ключ виджета; без него ключ строится из класса и заголовка. */
    protected ?string $key = null;

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
     * Ширина в колонках сетки из четырёх: 1 — четверть, 4 — вся строка. На странице-отчёте с шестью колонками
     * (`Page::$columns = 6`) — доля от шести, до 6; на дашборде такой виджет занимает всю строку. На узких экранах
     * виджеты идут в столбик.
     */
    public function columnSpan(int $span): static
    {
        $this->span = max(1, min(6, $span));

        return $this;
    }

    /**
     * Высота в строках сетки: 1 — одна строка, 4 — четыре. Строка не ниже содержимого; на телефоне виджеты идут в столбик
     * и высота не учитывается.
     */
    public function rowSpan(int $rows): static
    {
        $this->rows = max(1, min(4, $rows));

        return $this;
    }

    /**
     * Постоянный ключ виджета: по нему хранятся настройки администратора (порядок, размер, скрытие). Латиница в нижнем регистре,
     * цифры и дефис, до 100 знаков. Без ключа он строится из класса и заголовка, и при смене заголовка настройки этого
     * виджета сбрасываются к значениям из кода.
     *
     * ```php
     * Stat::make('Заявки')->key('leads-today');
     * ```
     *
     * @throws InvalidArgumentException недопустимый ключ
     */
    public function key(string $key): static
    {
        if (preg_match('/^[a-z0-9-]{1,100}$/', $key) !== 1) {
            throw new InvalidArgumentException('Ключ виджета — латиница в нижнем регистре, цифры и дефис, до 100 знаков: '.$key);
        }

        $this->key = $key;

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
     * Высота в строках.
     */
    public function getRows(): int
    {
        return $this->rows;
    }

    /**
     * Заданный ключ (`key()`) или `null`.
     */
    public function getKey(): ?string
    {
        return $this->key;
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
