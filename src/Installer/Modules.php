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
            'contacts' => new Module('contacts', 'Контакты', 'Страница настроек «Контакты»: телефон, почта, адрес, часы работы', $root.'contacts', slugs: ['contacts']),
            'pages' => new Module('pages', 'Страницы', 'Раздел «Страницы» в панели: дерево страниц из блоков (текст, картинка, галерея, призыв) и SEO', $root.'pages', tables: ['pages'], slugs: ['site-pages'], seeders: ['Database\\Seeders\\Fastleo\\PageSeeder']),
            'news' => new Module('news', 'Новости', 'Раздел «Новости» в панели: обложка, анонс, текст, дата публикации и SEO', $root.'news', tables: ['news'], slugs: ['news'], widgets: [
                "\\Fastleo\\Widgets\\Table::make('Последние новости')->resource(\\{{ namespace }}\\NewsResource::class)->limit(5)->columnSpan(2),",
            ], seeders: ['Database\\Seeders\\Fastleo\\NewsSeeder']),
            'menu' => new Module('menu', 'Меню сайта', 'Раздел «Меню сайта» в панели: дерево пунктов шапки и подвала, порядок перетаскиванием', $root.'menu', tables: ['menu_items'], slugs: ['menu-items'], seeders: ['Database\\Seeders\\Fastleo\\MenuItemSeeder']),
            'reviews' => new Module('reviews', 'Отзывы', 'Раздел «Отзывы» в панели: автор, оценка, текст, фото, порядок перетаскиванием', $root.'reviews', tables: ['reviews'], slugs: ['reviews'], seeders: ['Database\\Seeders\\Fastleo\\ReviewSeeder']),
            'portfolio' => new Module('portfolio', 'Портфолио', 'Раздел «Портфолио» в панели: работы с обложкой, галереей, описанием, категорией и SEO', $root.'portfolio', tables: ['portfolio_items'], slugs: ['portfolio'], seeders: ['Database\\Seeders\\Fastleo\\PortfolioItemSeeder']),
            'faq' => new Module('faq', 'Вопросы и ответы', 'Раздел «Вопросы и ответы» в панели: вопрос, ответ, группа, порядок перетаскиванием', $root.'faq', tables: ['faq_items'], slugs: ['faq'], seeders: ['Database\\Seeders\\Fastleo\\FaqItemSeeder']),
            'shop' => new Module('shop', 'Интернет-магазин', 'Каталог и заказы в панели: категории (дерево), товары, заказы с позициями и статусами, ShopOrder::place() для корзины сайта', $root.'shop', tables: ['shop_categories', 'shop_products', 'shop_orders', 'shop_order_items'], slugs: ['shop-categories', 'shop-products', 'shop-orders', 'shop-order-items'], seeders: ['Database\\Seeders\\Fastleo\\ShopSeeder']),
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
            'basic' => 'Базовая установка: только панель, без готовых модулей',
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
