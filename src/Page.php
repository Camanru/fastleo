<?php

declare(strict_types=1);

namespace Fastleo;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Своя страница админки без модели: отчёт, инструкция, панель управления. Появляется в меню.
 * Класс лежит рядом с ресурсами (по умолчанию `app/Fastleo/`), шаблон — в `resources/views` сайта.
 *
 * ```php
 * namespace App\Fastleo;
 *
 * final class ReportPage extends \Fastleo\Page
 * {
 *     protected static ?string $label = 'Отчёт по заявкам';
 *     protected static string $icon = 'chart-bar';
 *
 *     public function view(): string
 *     {
 *         return 'fastleo.report';   // resources/views/fastleo/report.blade.php
 *     }
 *
 *     public function data(Request $request): array
 *     {
 *         return ['total' => Lead::count()];
 *     }
 * }
 * ```
 *
 * ```blade
 * <x-fastleo::layouts.app title="Отчёт по заявкам">
 *     <x-fastleo::card title="Всего заявок">{{ $total }}</x-fastleo::card>
 * </x-fastleo::layouts.app>
 * ```
 *
 * Адрес — `/fastleo/pages/{slug}`; класс `ReportPage` → `report`. Форма на своей странице отправляется на маршрут
 * сайта, объявленный через `Fastleo::routes()` (с защитой админки и CSRF).
 */
abstract class Page
{
    /** Название страницы в меню. По умолчанию — имя класса без «Page». */
    protected static ?string $label = null;

    /** Иконка Lucide в меню. */
    protected static string $icon = 'file-text';

    /** Группа меню; по умолчанию без группы. */
    protected static ?string $group = null;

    /** Позиция в меню внутри группы. */
    protected static int $position = 0;

    /** Часть адреса `/fastleo/pages/{slug}`. По умолчанию — kebab имени класса без «Page». */
    protected static ?string $slug = null;

    /**
     * Blade-шаблон страницы (в нём подключается `<x-fastleo::layouts.app>`).
     */
    abstract public function view(): string;

    /**
     * Данные для шаблона.
     *
     * @return array<string, mixed>
     */
    public function data(Request $request): array
    {
        return [];
    }

    /**
     * Страница сейчас существует: если `false`, её нет в меню и поиске, а адрес отвечает 404 (а не «нет доступа»).
     * По умолчанию — да; нужен страницам, которые появляются при условии (например, подключение к сервису).
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Администратору можно открывать страницу. По умолчанию — всем администраторам.
     */
    public function canAccess(?Authenticatable $user): bool
    {
        return true;
    }

    /**
     * Название страницы.
     */
    public static function label(): string
    {
        return static::$label ?? Str::headline(self::baseName());
    }

    /**
     * Иконка в меню.
     */
    public static function icon(): string
    {
        return static::$icon;
    }

    /**
     * Группа меню или null.
     */
    public static function group(): ?string
    {
        return static::$group;
    }

    /**
     * Позиция в меню.
     */
    public static function position(): int
    {
        return static::$position;
    }

    /**
     * Часть адреса страницы.
     */
    public static function slug(): string
    {
        return static::$slug ?? Str::kebab(self::baseName());
    }

    /**
     * Имя класса без слова «Page».
     */
    private static function baseName(): string
    {
        $name = class_basename(static::class);

        return Str::replaceEnd('Page', '', $name) ?: $name;
    }
}
