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
            'contacts' => new Module('contacts', 'Контакты', 'Страница настроек «Контакты»: телефон, почта, адрес, часы работы; на сайте — страница /contacts', $root.'contacts', uris: ['contacts'], slugs: ['contacts']),
            'pages' => new Module('pages', 'Страницы', 'Раздел «Страницы» в панели: дерево страниц из блоков (текст, картинка, галерея, призыв) и SEO; на сайте — главная и любой адрес страницы', $root.'pages', tables: ['pages'], slugs: ['site-pages'], seeders: ['Database\\Seeders\\PageSeeder'], takesFallback: true, takesHome: true, siteNote: 'Страницы: без страницы с отметкой «Главная» адрес «/» отдаёт 404. До php artisan migrate адрес «/» и любой адрес, похожий на страницу (/about), закончатся ошибкой: таблицы pages ещё нет. Запасной маршрут стартует сессию на каждый несуществующий адрес: при SESSION_DRIVER=database боты (/wp-login.php, /.env) заполнят таблицу sessions — на посещаемом сайте выберите redis.'),
            'news' => new Module('news', 'Новости', 'Раздел «Новости» в панели: обложка, анонс, текст, дата публикации и SEO; на сайте — /news и /news/{адрес}', $root.'news', tables: ['news'], uris: ['news'], slugs: ['news'], widgets: [
                "\\Fastleo\\Widgets\\Table::make('Последние новости')->resource(\\{{ namespace }}\\NewsResource::class)->limit(5)->columnSpan(2),",
            ], seeders: ['Database\\Seeders\\NewsSeeder']),
            'menu' => new Module('menu', 'Меню сайта', 'Раздел «Меню сайта» в панели: дерево пунктов шапки и подвала, порядок перетаскиванием; на сайте — шаблоны меню шапки и подвала', $root.'menu', tables: ['menu_items'], slugs: ['menu-items'], seeders: ['Database\\Seeders\\MenuItemSeeder']),
            'reviews' => new Module('reviews', 'Отзывы', 'Раздел «Отзывы» в панели: автор, оценка, текст, фото, порядок перетаскиванием; на сайте — страница /reviews', $root.'reviews', tables: ['reviews'], uris: ['reviews'], slugs: ['reviews'], seeders: ['Database\\Seeders\\ReviewSeeder']),
            'portfolio' => new Module('portfolio', 'Портфолио', 'Раздел «Портфолио» в панели: работы с обложкой, галереей, описанием, категорией и SEO; на сайте — /portfolio и /portfolio/{адрес}', $root.'portfolio', tables: ['portfolio_items'], uris: ['portfolio'], slugs: ['portfolio'], seeders: ['Database\\Seeders\\PortfolioItemSeeder']),
            'faq' => new Module('faq', 'Вопросы и ответы', 'Раздел «Вопросы и ответы» в панели: вопрос, ответ, группа, порядок перетаскиванием; на сайте — страница /faq', $root.'faq', tables: ['faq_items'], uris: ['faq'], slugs: ['faq'], seeders: ['Database\\Seeders\\FaqItemSeeder']),
            'shop' => new Module('shop', 'Интернет-магазин', 'Каталог и заказы в панели: категории (дерево), товары, заказы с позициями и статусами; на сайте — каталог, корзина и оформление заказа (/shop, /cart, /checkout)', $root.'shop', tables: ['shop_categories', 'shop_products', 'shop_orders', 'shop_order_items'], uris: ['shop', 'cart', 'checkout'], slugs: ['shop-categories', 'shop-products', 'shop-orders', 'shop-order-items'], seeders: ['Database\\Seeders\\ShopSeeder'], siteNote: 'Магазин: заказы оформляются без предоплаты и сразу списывают остаток, поэтому защита держится на метке формы, ловушке для ботов и лимите 5 отправок формы за 10 минут с одного IP: за Cloudflare, nginx или другим прокси настройте trustProxies (vendor/camanru/fastleo/docs/security.md), иначе лимит станет общим для всего сайта. Нужен рабочий кеш (CACHE_STORE не null и не array; для database — таблица кеша): на нём держится одноразовость формы. Распределённый бот может «выкупить» остаток до ручной отмены заказов — следите за разделом «Заказы».'),
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

    /**
     * Каталог общих файлов публичной части (макет сайта): ставится с публичной частью любого модуля, если файла ещё нет.
     */
    public static function sharedDirectory(): string
    {
        return dirname(__DIR__, 2).'/stubs/modules/_site';
    }
}
