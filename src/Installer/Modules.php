<?php

declare(strict_types=1);

namespace Fastleo\Installer;

/**
 * Встроенные модули установки (чужие модули регистрировать нельзя).
 *
 * ```php
 * Modules::find('contacts');   // Module или null
 * ```
 */
final class Modules
{
    /**
     * Все модули по имени, в порядке показа.
     *
     * @return array<string, Module>
     */
    public static function all(): array
    {
        $root = dirname(__DIR__, 2).'/stubs/modules/';

        return [
            'contacts' => new Module('contacts', 'Контакты', 'Страница настроек «Контакты»: телефон, почта, адрес, часы работы', $root.'contacts'),
            'pages' => new Module('pages', 'Страницы', 'Раздел «Страницы» в админке: дерево страниц из блоков (текст, картинка, галерея, призыв) и SEO', $root.'pages', tables: ['pages'], seeders: ['Database\\Seeders\\PageSeeder']),
            'news' => new Module('news', 'Новости', 'Раздел «Новости» в админке: обложка, анонс, текст, дата публикации и SEO', $root.'news', tables: ['news'], widgets: [
                "\\Fastleo\\Widgets\\Table::make('Последние новости')->resource(\\{{ namespace }}\\NewsResource::class)->limit(5)->columnSpan(2),",
            ], seeders: ['Database\\Seeders\\NewsSeeder']),
            'leads' => new Module('leads', 'Заявки', 'Приём заявок с сайта (POST /leads, защита от ботов без внешних сервисов) и раздел «Заявки» с отметкой «просмотрено» и уведомлением администраторам', $root.'leads', tables: ['leads'], uris: ['POST leads'], widgets: [
                "\\Fastleo\\Widgets\\Stat::make('Новые заявки')->value(fn (): int => \\{{ appNamespace }}\\Models\\Lead::query()->whereNull('read_at')->count())->icon('inbox')->url(fn (): string => route('fastleo.resources.index', 'leads')),",
                "\\Fastleo\\Widgets\\Chart::make('Заявки за 30 дней')->line()->data(fn (): array => \\Fastleo\\Widgets\\Chart::countByDay(\\{{ appNamespace }}\\Models\\Lead::class))->columnSpan(3),",
            ], note: 'Заявки: лимит — 5 заявок за 10 минут с одного IP. За Cloudflare, nginx или другим прокси настройте trustProxies (vendor/camanru/fastleo/docs/security.md), иначе лимит станет общим для всего сайта: пять запросов одного недоброжелателя закроют форму для всех на 10 минут.'),
        ];
    }

    /**
     * Виды установки для опроса: имя → подпись. `basic` — без готовых модулей, `custom` — с выбором модулей.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            'basic' => 'Базовая установка: только админка, без готовых модулей',
            'custom' => 'Кастомная установка: выберу модули сам',
        ];
    }

    /**
     * Модуль по имени.
     */
    public static function find(string $name): ?Module
    {
        return self::all()[$name] ?? null;
    }
}
