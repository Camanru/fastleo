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
            'landing' => new Module('landing', 'Лендинг', 'Раздел «Лендинг» в панели: длинные страницы из блоков (первый экран, преимущества, этапы, тарифы, отзывы, вопросы, форма заявки) и SEO; у каждой свой адрес («/» — главная, «/promo»)', $root.'landing', tables: ['landing_pages'], slugs: ['landing'], seeders: ['Database\\Seeders\\LandingPageSeeder'], takesHome: true, siteNote: 'Лендинг: у каждого лендинга свой адрес («/» — главная, «/promo»); пока лендинга с адресом «/» нет или он не опубликован, «/» отдаётся запасному маршруту сайта (например, «Страницам»), а без него отвечает 404 (пример на «/» — php artisan db:seed --class=\'Database\\Seeders\\LandingPageSeeder\'). Форма заявки в блоке «Форма заявки» работает, когда заявки включены в config/fastleo.php (leads.resource), и не должна кешироваться страницей целиком. До php artisan migrate лендинг отвечает ошибкой на адреса из одного сегмента (/wp-login), а не 404. Все блоки лендинга отправляют полей больше обычной формы: на PHP с max_input_vars меньше 1000 форма раздела не сохранится — поднимите до 5000. Модуль «Страницы» можно ставить рядом: остальные адреса (/about) открывает он, а его главная страница не откроется, пока есть лендинг с адресом «/». Стандартный tests/Feature/ExampleTest.php нового Laravel после установки падает (он открывает «/» без базы): добавьте в него RefreshDatabase и лендинг или удалите тест.', takesAnySegment: true),
            'news' => new Module('news', 'Новости', 'Раздел «Новости» в панели: обложка, анонс, текст, дата публикации и SEO; на сайте — /news и /news/{адрес}', $root.'news', tables: ['news'], uris: ['news'], slugs: ['news'], widgets: [
                "\\Fastleo\\Widgets\\Table::make('Последние новости')->resource(\\{{ namespace }}\\NewsResource::class)->limit(5)->columnSpan(2),",
            ], seeders: ['Database\\Seeders\\NewsSeeder']),
            'menu' => new Module('menu', 'Меню сайта', 'Раздел «Меню сайта» в панели: дерево пунктов шапки и подвала, порядок перетаскиванием; на сайте — шаблоны меню шапки и подвала', $root.'menu', tables: ['menu_items'], slugs: ['menu-items'], seeders: ['Database\\Seeders\\MenuItemSeeder']),
            'reviews' => new Module('reviews', 'Отзывы', 'Раздел «Отзывы» в панели: автор, оценка, текст, фото, порядок перетаскиванием; на сайте — страница /reviews', $root.'reviews', tables: ['reviews'], uris: ['reviews'], slugs: ['reviews'], seeders: ['Database\\Seeders\\ReviewSeeder']),
            'portfolio' => new Module('portfolio', 'Портфолио', 'Раздел «Портфолио» в панели: работы с обложкой, галереей, описанием, категорией и SEO; на сайте — /portfolio и /portfolio/{адрес}', $root.'portfolio', tables: ['portfolio_items'], uris: ['portfolio'], slugs: ['portfolio'], seeders: ['Database\\Seeders\\PortfolioItemSeeder']),
            'faq' => new Module('faq', 'Вопросы и ответы', 'Раздел «Вопросы и ответы» в панели: вопрос, ответ, группа, порядок перетаскиванием; на сайте — страница /faq', $root.'faq', tables: ['faq_items'], uris: ['faq'], slugs: ['faq'], seeders: ['Database\\Seeders\\FaqItemSeeder']),
            'services' => new Module('services', 'Услуги', 'Раздел «Услуги» в панели: название, категория, цена, обложка, описание и SEO, порядок перетаскиванием; на сайте — /services и /services/{адрес}', $root.'services', tables: ['services'], uris: ['services'], slugs: ['services'], seeders: ['Database\\Seeders\\ServiceSeeder']),
            'prices' => new Module('prices', 'Прайс-лист', 'Раздел «Прайс-лист» в панели: позиции с ценой, единицей и группой, порядок перетаскиванием; на сайте — страница /prices', $root.'prices', tables: ['price_items'], uris: ['prices'], slugs: ['prices'], seeders: ['Database\\Seeders\\PriceItemSeeder']),
            'team' => new Module('team', 'Команда', 'Раздел «Команда» в панели: сотрудники с фото, должностью, стажем, описанием и SEO, порядок перетаскиванием; на сайте — /team и /team/{адрес}', $root.'team', tables: ['team_members'], uris: ['team'], slugs: ['team'], seeders: ['Database\\Seeders\\TeamMemberSeeder']),
            'branches' => new Module('branches', 'Филиалы и адреса', 'Раздел «Филиалы и адреса» в панели: адрес, телефоны, часы работы, координаты, порядок перетаскиванием; на сайте — страница /branches со ссылкой «Показать на карте»', $root.'branches', tables: ['branches'], uris: ['branches'], slugs: ['branches'], seeders: ['Database\\Seeders\\BranchSeeder']),
            'certificates' => new Module('certificates', 'Лицензии и сертификаты', 'Раздел «Документы» в панели (лицензии и сертификаты): название, скан, файл PDF, дата выдачи, порядок перетаскиванием; на сайте — страница /certificates', $root.'certificates', tables: ['certificates'], uris: ['certificates'], slugs: ['certificates'], seeders: ['Database\\Seeders\\CertificateSeeder']),
            'partners' => new Module('partners', 'Партнёры и клиенты', 'Раздел «Партнёры и клиенты» в панели: название, логотип, ссылка, порядок перетаскиванием; на сайте — страница /partners', $root.'partners', tables: ['partners'], uris: ['partners'], slugs: ['partners'], seeders: ['Database\\Seeders\\PartnerSeeder']),
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
