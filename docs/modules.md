# Готовые модули сайта

Команда `fastleo:module` копирует в сайт готовый раздел панели: страницу настроек, модель, миграцию, ресурс, виджеты главной страницы. **Публичную часть сайта (вёрстку, шаблоны, страницы) модули не создают** — её делает сайт сам. Заявки с сайта и по API — не модуль, а встроенная часть пакета: [leads.md](leads.md). С этого момента код принадлежит сайту: его правят под себя, обновление fastleo его не трогает.

```bash
php artisan fastleo:module            # список с галочками
php artisan fastleo:module contacts   # без вопросов (для Claude и CI)
```

## Что делает команда

- Сначала проверяет всё: файлы, таблицы, адреса разделов панели (свой раздел сайта с адресом `news` помешает модулю `news`) и адреса сайта, которые займёт модуль. Адрес занят, если у сайта есть маршрут с таким адресом или под ним (`shop` и `shop/{id}`); если модуль называет метод (`POST shop`), занятым считается только он, обычная страница `GET /shop` не мешает. Сейчас готовые модули адресов сайта не занимают. Если что-то занято, **не записывается ничего**; команда печатает список занятого. Чужой код не перезаписывается, ключа `--force` нет — снимите модуль с выбора или освободите место.
- Миграции получают текущую метку времени в имени; код модулей получает пространство имён разделов (`fastleo.resources.namespace`, обычно `App\Fastleo`).
- **Код модулей лежит отдельно от вашего.** Всё, что у модуля своё, живёт в папке разделов панели с пространством имён `App\Fastleo\…`: модели — `App\Fastleo\Models\Page` (`app/Fastleo/Models`), enum и исключения — `app/Fastleo/Enums`, `app/Fastleo/Exceptions`; фабрики — `Database\Factories\Fastleo\…` (`database/factories/Fastleo`), сидеры — `Database\Seeders\Fastleo\…`, тесты — `tests/Feature/Fastleo`. Поэтому модуль не пересекается с вашими `App\Models\Page`, `PageFactory` и т. п. и не требует их удалять. Остаётся только таблица (`pages`, `news`, …): если такая уже есть, модуль не ставится. Если вы меняли `fastleo.resources.namespace`, `App\Fastleo` в примерах замените на своё пространство.
- Если модуль добавляет маршрут (`routes/site/*.php`; сейчас готовых модулей с маршрутами нет), в конец `routes/web.php` один раз дописывается подключение всех файлов `routes/site/*.php` (с пометкой `// fastleo: маршруты готовых модулей`).
- Тесты модулей (`tests/Feature/Fastleo/…`) копируются, только если на сайте есть Pest: `pestphp/pest` в `composer.json` и файл `tests/Pest.php` (его создаёт `vendor/bin/pest --init`; без него тесты не знают про Laravel и падают). Иначе команда напомнит, где они лежат.
- Виджеты модуля дописываются в `widgets()` класса `Dashboard` (`app/Fastleo/Dashboard.php`); если класса нет, он создаётся (как `fastleo:make-dashboard`). Повторная установка не дублирует виджеты. Если `Dashboard` устроен необычно и конец списка не найден, команда ничего не меняет и печатает строки, которые нужно дописать самим.
- В конце печатает список созданных файлов.

## Модули

| Имя | Что получит сайт |
|---|---|
| `contacts` | Страница настроек «Контакты» (`app/Fastleo/ContactsSettings.php`): телефон, почта, адрес, часы работы. На сайте — `fastleo_setting('contacts.phone')` и другие ключи |
| `pages` | Раздел «Страницы» (`PageResource`, адрес в панели `site-pages`): дерево с ручным порядком, заголовок, адрес, «Опубликована», «В меню сайта», «Главная», содержимое из блоков (текст, картинка, галерея, призыв), SEO. Модель `Page` со скоупом `published()`. Подробнее — [ниже](#страницы) |
| `menu` | Раздел «Меню сайта» (`MenuItemResource`, адрес в панели `menu-items`): дерево пунктов с ручным порядком, название, адрес, «Шапка» или «Подвал», «Открывать в новой вкладке», «Показывать». Модель `MenuItem`: `MenuItem::tree('header')` отдаёт готовое дерево. Подробнее — [ниже](#меню-сайта) |
| `reviews` | Раздел «Отзывы» (`ReviewResource`, адрес `reviews`): автор, должность или компания, оценка 1–5, текст, фото, дата, «Опубликован»; порядок — перетаскиванием. Модель `Review` со скоупом `published()` |
| `portfolio` | Раздел «Портфолио» (`PortfolioResource`, адрес `portfolio`): название, адрес, категория, заказчик, дата, обложка, галерея, кратко, описание (RichText), SEO, «Опубликована»; порядок — перетаскиванием. Модель `PortfolioItem` со скоупом `published()` |
| `faq` | Раздел «Вопросы и ответы» (`FaqResource`, адрес `faq`): вопрос, ответ (RichText), необязательная группа, «Опубликован»; порядок — перетаскиванием. Модель `FaqItem` со скоупом `published()` |
| `shop` | Каталог и заказы в панели: «Категории» (дерево), «Товары» (цена, остаток, фото, характеристики, SEO) и «Заказы» (статусы, «Оплачен», позиции). Модели `ShopCategory`, `ShopProduct`, `ShopOrder`, `ShopOrderItem`; `ShopOrder::place()` создаёт заказ из корзины сайта. Подробнее — [ниже](#интернет-магазин) |
| `news` | Раздел «Новости» (`NewsResource`, адрес `news`): заголовок, адрес, дата публикации, «Опубликована», обложка, анонс, текст (RichText), SEO; виджет «Последние новости». Модель `News` со скоупом `published()`. Дата публикации в будущем откладывает показ до этой даты (в `published()`), пустая дата — показывать сразу |

## Страницы

Модуль даёт данные и раздел панели; показ страниц на сайте — ваш. Пример (маршрут, который проверяется последним, чтобы не перехватывать остальные адреса сайта):

```php
// routes/web.php (после остальных маршрутов)
use Illuminate\Http\Request;

Route::fallback(function (Request $request) {
    $page = null;

    foreach (explode('/', $request->path()) as $slug) {
        $page = \App\Fastleo\Models\Page::query()->published()->where('slug', $slug)->where('parent_id', $page?->id)->first();
        abort_if($page === null, 404);
    }

    return view('pages.show', ['page' => $page]);
});
```

```blade
{{-- resources/views/pages/show.blade.php --}}
<x-fastleo::seo :model="$page" :title="$page->title" />
<h1>{{ $page->title }}</h1>
<x-fastleo::blocks :blocks="$page->content" />
```

- Слаг уникален во всей таблице; адрес вложенной страницы — слаги родителей и её собственный (`about/team`). Главная — страница с отметкой «Главная»: `Page::query()->published()->where('is_home', true)->first()`.
- Шаблоны блоков (`text`, `image`, `gallery`, `cta`) пишите сами: `resources/views/blocks/{тип}.blade.php` ([блоки](blocks.md)). **Пока шаблона блока нет, `<x-fastleo::blocks>` выводит пустоту** — страница покажет только заголовок. Ссылку блока «Призыв» (`url`) выводите, только проверив начало (`/` или `http(s)://`): значение вводит пользователь, но `javascript:` в ссылку попадать не должно.
- Запасной маршрут в группе `web` стартует сессию на каждый несуществующий адрес (`/wp-login.php`, `/.env` — их перебирают боты): на сайте с большим посещением храните сессии в Redis (`SESSION_DRIVER=redis`) и задайте небольшой `SESSION_LIFETIME`.
- **Примеры.** `php artisan db:seed --class='Database\Seeders\Fastleo\PageSeeder'` создаёт «Главную» и «О компании». Фабрика — `Page::factory()`.

## Меню сайта

Модуль даёт данные и раздел панели; вёрстку меню делаете вы. В списке пункты переставляют и вкладывают друг в друга перетаскиванием (дерево, как у «Страниц»). Пункт видит сайт, только если он и все его родители «Показываются»:

```blade
{{-- шапка сайта --}}
<nav>
    @foreach (\App\Fastleo\Models\MenuItem::tree('header') as $item)
        <a href="{{ $item->url }}" @if ($item->new_tab) target="_blank" rel="noopener" @endif>{{ $item->title }}</a>

        @foreach ($item->children as $child)
            <a href="{{ $child->url }}">{{ $child->title }}</a>
        @endforeach
    @endforeach
</nav>
```

- `tree('footer')` — меню подвала. Один запрос на всё меню, `children` заполнены на любую глубину.
- Адрес в форме принимает только путь сайта (`/contacts`) или `http(s)://…`: `javascript:` и `//чужой-сайт` отклоняются. Свой адрес `/` выводите как есть.
- Вложенный пункт другого места (родитель в «Шапке», он в «Подвале») на сайте не показывается ни там, ни там: держите ветку в одном месте.
- **Примеры.** `php artisan db:seed --class='Database\Seeders\Fastleo\MenuItemSeeder'`. Фабрика — `MenuItem::factory()`.

## Отзывы, портфолио, вопросы и ответы

У этих трёх модулей общий порядок работы: данные — `Review`, `PortfolioItem`, `FaqItem`, у каждого скоуп `published()` (опубликованные в порядке, заданном перетаскиванием в списке). Показ — ваши маршруты и шаблоны:

```php
\App\Fastleo\Models\Review::query()->published()->get();
\App\Fastleo\Models\PortfolioItem::query()->published()->paginate(12);
\App\Fastleo\Models\PortfolioItem::query()->published()->where('slug', $slug)->firstOrFail();
\App\Fastleo\Models\FaqItem::query()->published()->get()->groupBy('category'); // вопросы по группам
```

- **Отзывы:** оценка — целое число от 1 до 5; фото лежит в `storage/app/public/uploads/reviews` (`php artisan storage:link`). Выводите картинку через `<x-fastleo::picture>` ([файлы](faq/files.md)).
- **Портфолио:** обложка и галерея лежат в `storage/app/public/uploads/portfolio`; категория — обычная строка (`where('category', 'Сайты')`); `photos` — список путей галереи (`$item->photos`); SEO — `<x-fastleo::seo :model="$item" />`.
- **Вопросы и ответы:** ответ — RichText (чистый HTML, выводите `{!! $item->answer !!}`); для разметки Schema.org соберите JSON-LD из `FaqItem::query()->published()->get()`.
- **Примеры.** `php artisan db:seed --class='Database\Seeders\Fastleo\ReviewSeeder'` (и `PortfolioItemSeeder`, `FaqItemSeeder` в том же пространстве имён). Фабрики — `Review::factory()` и т. д.

## Интернет-магазин

Модуль `shop` даёт каталог и заказы **в панели**; витрину, корзину и оформление заказа делает сайт сам (как и у остальных модулей — публичной части нет).

```bash
php artisan fastleo:module shop
php artisan migrate
php artisan db:seed --class='Database\Seeders\Fastleo\ShopSeeder'   # по желанию: категория, три товара и заказ
```

Разделы стоят в одной группе меню «Магазин»: «Заказы» (число новых — в меню), «Товары», «Категории». Таблицы — `shop_categories`, `shop_products`, `shop_orders`, `shop_order_items` (одна миграция); имена с префиксом, чтобы не пересекаться с таблицами сайта.

- **Товары.** Название, адрес, категория (одна), артикул, цена, остаток, фото, галерея, описание, характеристики («параметр — значение»), SEO. Цена — `decimal(12,2)`, в модели — строка с копейками (`'1500.00'`). **Остаток необязателен:** пусто — не учитывается (услуга, товар под заказ), число — заказ списывает его сам. Порядок на сайте — перетаскиванием в списке.
- **Категории.** Дерево; порядок и вложенность — перетаскиванием.
- **Заказы.** Покупатель хранится снимком в самом заказе (имя, телефон, почта, адрес, комментарий): таблицы клиентов и личных кабинетов нет. Позиции — снимок названия, артикула и цены на момент заказа, они видны таблицей под формой и не правятся (ошибся покупатель — заказ отменяют и создают новый). Менеджер меняет **статус** (действие «Сменить статус», в том числе для выбранных), галочку «Оплачен» (дата оплаты ставится сама), контакты и заметку. Заказ вручную в панели не создаётся: только кодом сайта.
- **Статусы:** «Новый», «В работе», «Отправлен», «Выполнен», «Отменён» (`App\Fastleo\Enums\ShopOrderStatus`, подписи и цвета меток — в нём). «Отменён» — конечный: заказ из него не выходит, а при отмене остаток, списанный этим заказом, возвращается на склад (один раз; товарам, у которых при заказе остаток не вёлся, ничего не добавляется). Удалить можно только отменённый заказ.

### Создание заказа: `ShopOrder::place()`

Корзину сайт хранит как хочет (сессия, cookie). Заказ создавайте **только** так: цены и названия берутся из базы (а не из запроса покупателя), остаток списывается атомарно (два заказа одновременно не уведут его в минус), всё в одной транзакции, а пользователям панели уходит уведомление во включённых [каналах](notifications.md) (в тексте только номер и сумма).

```php
use App\Fastleo\Models\ShopOrder;

$order = ShopOrder::place(
    ['name' => 'Иван', 'phone' => '+7 900 000-00-00', 'email' => 'ivan@example.com', 'address' => 'Тюмень, ул. Ленина, 1', 'delivery' => 'Курьер', 'comment' => 'Позвоните заранее'],
    [12 => 2, 15 => 1], // id товара => количество
);
```

Метод бросает `App\Fastleo\Exceptions\OutOfStockException` (товара нет в нужном количестве; `getMessage()` можно показать покупателю: «Лампа»: в наличии 1 шт.) и `InvalidArgumentException` (пустая корзина, количество не целое или меньше 1, товар не найден или скрыт, нет имени или телефона). При любой ошибке заказ не создаётся, остаток не меняется.

Пример оформления: проверка формы и вызов `place()` в контроллере (маршрут — ваш; ограничьте частоту запросов, например `throttle:10,1`):

```bash
php artisan make:request CheckoutRequest      # app/Http/Requests/CheckoutRequest.php
php artisan make:controller CheckoutController
```

```php
// app/Http/Requests/CheckoutRequest.php
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:150'],
        'phone' => ['required', 'string', 'max:50', 'regex:/^[0-9+()\-\s]{6,}$/'],
        'email' => ['nullable', 'email', 'max:255'],
        'address' => ['nullable', 'string', 'max:1000'],
        'comment' => ['nullable', 'string', 'max:2000'],
    ];
}
```

```php
// app/Http/Controllers/CheckoutController.php
use App\Fastleo\Exceptions\OutOfStockException;
use App\Fastleo\Models\ShopOrder;
use App\Http\Requests\CheckoutRequest;
use InvalidArgumentException;

public function store(CheckoutRequest $request)
{
    $cart = $request->session()->get('cart', []); // [id товара => количество]

    try {
        $order = ShopOrder::place($request->validated(), $cart);
    } catch (OutOfStockException $e) {
        return back()->withErrors(['cart' => $e->getMessage()]);
    } catch (InvalidArgumentException) {
        // Корзина пуста или устарела: товар скрыли или удалили, пока он лежал в сессии.
        $request->session()->forget('cart');

        return back()->withErrors(['cart' => 'Корзина устарела, соберите её заново.']);
    }

    $request->session()->forget('cart');

    return redirect()->route('checkout.thanks', $order);
}
```

Вывод на сайте:

```php
\App\Fastleo\Models\ShopCategory::query()->published()->whereNull('parent_id')->get();
\App\Fastleo\Models\ShopProduct::query()->published()->inStock()->where('category_id', $category->id)->paginate(24);
\App\Fastleo\Models\ShopProduct::query()->published()->where('slug', $slug)->firstOrFail(); // $product->specs — массив «параметр => значение»
```

- **Не входит:** варианты товара (размер, цвет), клиенты и личные кабинеты, оплата через платёжные системы (сайт подключает сам и ставит «Оплачен»), скидки и промокоды, стоимость доставки, несколько валют и налоги. Валюта — подпись «₽» в ресурсах и в тексте уведомления; поменяйте в коде сайта.
- **Фото товаров** лежат в `storage/app/public/uploads/shop` (`php artisan storage:link`); выводите через `<x-fastleo::picture>` ([файлы](faq/files.md)).
- **Примеры.** `ShopSeeder`; фабрики — `ShopCategory::factory()`, `ShopProduct::factory()`, `ShopOrder::factory()` (заказ без позиций; настоящий создаёт `place()`).

## Подогнать модуль под сайт

Модуль — **стартовый код сайта**, а не зависимость: файлы копируются в ваш `app/` один раз, обновлений и точек расширения у них нет, правьте их как свои. Что менять чаще всего:

- **Другая модель данных** (новости со статусами вместо «Опубликована»): модуль даёт 10 минут экономии на каркасе. Сделайте новую миграцию `alter`, перепишите ресурс, модель и фабрику, удалите сидер модуля (`NewsSeeder`) и строку виджета в `app/Fastleo/Dashboard.php`.
- **Группа в меню панели.** Разделы модулей (`pages`, `news`, `menu`, `reviews`, `portfolio`, `faq`) стоят без группы — в верхней части меню под «Дашбордом»; три раздела «Интернет-магазина» собраны в группу «Магазин». Чтобы поместить раздел в группу (существующую или новую), добавьте в его класс свойство, например `protected static ?string $group = 'Содержимое';`; порядок пунктов внутри — `$position` ([ресурсы](resources.md)).
- **Страница настроек «Контакты»**: если на сайте уже есть своя страница настроек, модуль `contacts` не ставьте — две страницы с телефоном запутают редакторов.
- **Тесты модулей** копируются только в сайт с Pest (`tests/Feature/Fastleo/…`); на сайте с PHPUnit напишите их сами.

## Миграции

Модули (`pages`, `news`, `menu`, `reviews`, `portfolio`, `faq`, `shop`) кладут в `database/migrations` свои миграции (метка времени в имени — момент установки). Таблиц ещё нет, пока вы не выполните `php artisan migrate`; `fastleo:install` делает это за вас по вашему «да» или с `--setup`, а `fastleo:module` в конце печатает «Дальше»: выполнить `php artisan migrate` и, по желанию, добавить примеры данных (`php artisan db:seed --class='Database\Seeders\Fastleo\MenuItemSeeder'` — по сидеру на каждый добавленный модуль с таблицей). Миграции модулей не зависят друг от друга. Если в базе уже есть таблица с таким именем (`pages`, `news`, `menu_items`, `reviews`, `portfolio_items`, `faq_items`, `shop_categories`, `shop_products`, `shop_orders`, `shop_order_items`), модуль не ставится.

## Как убрать модуль

Модуль — обычные файлы сайта: удалите те, что перечислила команда при установке (список также виден в `git status`), и таблицу, если модуль её создавал (`php artisan migrate:rollback` для миграции модуля или `Schema::drop`). Кроме файлов, модуль оставляет следы в двух местах:

- **`app/Fastleo/Dashboard.php`** — строки виджетов в `widgets()`: `news` — «Последние новости» (`Table`). Удалите их, иначе главная страница панели упадёт без модели.
- **`routes/web.php`** — блок с пометкой `// fastleo: маршруты готовых модулей` (подключает `routes/site/*.php`). Он общий для всех модулей: уберите его, только когда в `routes/site/` ничего не осталось.

| Модуль | Файлы |
|---|---|
| `contacts` | `app/Fastleo/ContactsSettings.php` |
| `pages` | `app/Fastleo/Models/Page.php`, `app/Fastleo/PageResource.php`, миграция `*_create_pages_table.php`, `database/factories/Fastleo/PageFactory.php`, `database/seeders/Fastleo/PageSeeder.php`, `tests/Feature/Fastleo/PagesTest.php` |
| `news` | `app/Fastleo/Models/News.php`, `app/Fastleo/NewsResource.php`, миграция `*_create_news_table.php`, `database/factories/Fastleo/NewsFactory.php`, `database/seeders/Fastleo/NewsSeeder.php`, `tests/Feature/Fastleo/NewsTest.php`, виджет в `Dashboard.php` |
| `menu` | `app/Fastleo/Models/MenuItem.php`, `app/Fastleo/MenuItemResource.php`, миграция `*_create_menu_items_table.php`, `database/factories/Fastleo/MenuItemFactory.php`, `database/seeders/Fastleo/MenuItemSeeder.php`, `tests/Feature/Fastleo/MenuTest.php` |
| `reviews` | `app/Fastleo/Models/Review.php`, `app/Fastleo/ReviewResource.php`, миграция `*_create_reviews_table.php`, `database/factories/Fastleo/ReviewFactory.php`, `database/seeders/Fastleo/ReviewSeeder.php`, `tests/Feature/Fastleo/ReviewsTest.php` |
| `portfolio` | `app/Fastleo/Models/PortfolioItem.php`, `app/Fastleo/PortfolioResource.php`, миграция `*_create_portfolio_items_table.php`, `database/factories/Fastleo/PortfolioItemFactory.php`, `database/seeders/Fastleo/PortfolioItemSeeder.php`, `tests/Feature/Fastleo/PortfolioTest.php` |
| `shop` | `app/Fastleo/Models/Shop{Category,Product,Order,OrderItem}.php`, `app/Fastleo/Enums/ShopOrderStatus.php`, `app/Fastleo/Exceptions/OutOfStockException.php`, `app/Fastleo/Shop{Category,Product,Order,OrderItem}Resource.php`, `app/Fastleo/Actions/ChangeShopOrderStatus.php`, миграция `*_create_shop_tables.php` (при откате таблицы удаляются вместе с заказами), `database/factories/Fastleo/Shop{Category,Product,Order}Factory.php`, `database/seeders/Fastleo/ShopSeeder.php`, `tests/Feature/Fastleo/ShopTest.php` |
| `faq` | `app/Fastleo/Models/FaqItem.php`, `app/Fastleo/FaqResource.php`, миграция `*_create_faq_items_table.php`, `database/factories/Fastleo/FaqItemFactory.php`, `database/seeders/Fastleo/FaqItemSeeder.php`, `tests/Feature/Fastleo/FaqTest.php` |
