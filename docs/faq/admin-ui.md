# FAQ: Админка — дашборд, отчёты, меню, оформление

Главная страница и виджеты, отчёты с фильтрами, свои страницы, меню, цвета и шаблоны, часовой пояс, поиск, уведомления и журнал ошибок. Все вопросы — [список](README.md).

## Дашборд

### Как сделать свой дашборд?

Создайте класс `Dashboard` командой и перечислите в нём виджеты — пакет найдёт класс сам.

```bash
php artisan fastleo:make-dashboard
```

```php
// app/Fastleo/Dashboard.php
namespace App\Fastleo;

use App\Models\Lead;
use Fastleo\Widgets\Chart;
use Fastleo\Widgets\Sections;
use Fastleo\Widgets\ServerStatus;
use Fastleo\Widgets\Stat;
use Fastleo\Widgets\Table;

final class Dashboard extends \Fastleo\Dashboard
{
    public function widgets(): array
    {
        return [
            Stat::make('Заявки сегодня')->value(fn () => Lead::query()->whereDate('created_at', today())->count())->icon('inbox'),
            Chart::make('Заявки за 30 дней')->line()->data(fn () => Chart::countByDay(Lead::query()))->columnSpan(3),
            Table::make('Последние заявки')->resource(LeadResource::class)->limit(5)->columnSpan(2),
            Sections::make()->columnSpan(2),
            ServerStatus::make()->columnSpan(4),
        ];
    }
}
```

Если свой класс есть, встроенные плитки разделов и состояние сервера показываются, только если вы добавили их в список (`Sections`, `ServerStatus`). Подробнее — [свой дашборд](../dashboard.md#свой-дашборд).

### Как показать плитку с числом и ссылкой на раздел?

Добавьте виджет `Stat`: число, подпись под ним, иконка и ссылка.

```php
// app/Fastleo/Dashboard.php
use App\Models\Lead;
use App\Models\Order;
use Fastleo\Widgets\Stat;

Stat::make('Новые заявки')
    ->value(fn () => Lead::query()->whereNull('manager_id')->count())
    ->description(fn () => 'всего '.Lead::query()->count())
    ->icon('inbox')
    ->url(route('fastleo.resources.index', 'leads')),

Stat::make('Выручка')
    ->value(fn () => number_format((float) Order::query()->sum('total'), 0, ',', ' ').' ₽')
    ->description('за всё время')
    ->icon('wallet'),
```

Подробнее — [Stat](../dashboard.md#stat--число).

### Как добавить график?

Используйте `Chart`: `line()` или `bar()` и данные «подпись → число». Для «сколько записей в день» есть готовый `Chart::countByDay()`.

```php
// app/Fastleo/Dashboard.php
use App\Models\Lead;
use Fastleo\Widgets\Chart;

Chart::make('Заявки по дням')->line()
    ->data(fn () => Chart::countByDay(Lead::query(), 'created_at', 30))
    ->columnSpan(2),

Chart::make('По статусам')->bar()->label('Заявок')
    ->data(fn () => Lead::query()->whereNotNull('status')->selectRaw('status, count(*) as total')->groupBy('status')->get()
        ->mapWithKeys(fn ($row) => [$row->status->label() => (int) $row->total])->all())
    ->columnSpan(2),
```

`$row->status->label()` работает, если у модели есть enum-каст статуса (`'status' => LeadStatus::class` в `casts()`, у enum — метод `label()`). Без каста статус — строка: пишите `[$row->status => (int) $row->total]`.

Подробнее — [Chart](../dashboard.md#chart--график).

### Как показать последние записи раздела?

Добавьте виджет `Table` с классом ресурса: первые четыре столбца списка, новые сверху.

```php
use Fastleo\Widgets\Table;

Table::make('Последние заявки')->resource(LeadResource::class)->limit(10)->columnSpan(4),
```

Тот, кому Policy закрывает раздел, виджет не увидит. Подробнее — [Table](../dashboard.md#table--последние-записи).

### Как сделать свой виджет со своей разметкой?

Создайте заготовку командой, верните данные из `payload()` и выведите их в шаблоне.

```bash
php artisan fastleo:make-widget QueueSize
```

```php
// app/Fastleo/Widgets/QueueSize.php
namespace App\Fastleo\Widgets;

use Fastleo\Widgets\Widget;
use Illuminate\Support\Facades\DB;

final class QueueSize extends Widget
{
    public function view(): string
    {
        return 'fastleo.widgets.queue-size';
    }

    protected function payload(): array
    {
        return ['jobs' => DB::table('jobs')->count()];
    }
}
```

```blade
{{-- resources/views/fastleo/widgets/queue-size.blade.php --}}
<p class="queue-size">{{ $jobs }}</p>
<x-fastleo::button size="sm" icon="list" :href="route('fastleo.logs.index')">Журнал</x-fastleo::button>
```

```php
// app/Fastleo/Dashboard.php
\App\Fastleo\Widgets\QueueSize::make('Задач в очереди'),
```

Классы Tailwind в шаблоне сайта не работают: берите компоненты пакета или свой CSS ([свой CSS](#как-подключить-свой-css-и-поменять-цвета)). Подробнее — [свой виджет](../dashboard.md#свой-виджет), [компоненты](../theming.md#компоненты-для-своих-шаблонов).

### Как показать виджет только части администраторов?

Передайте в `visible()` замыкание — оно получает администратора.

```php
// is_owner — колонка вашей модели пользователя
Stat::make('Выручка')->value(fn () => Order::query()->sum('total'))
    ->visible(fn ($user) => $user->is_owner),
```

Скрытый виджет не считает данные. Подробнее — [раскладка](../dashboard.md#раскладка).

### Как задать размер и порядок виджетов?

Виджеты идут в порядке списка в сетке из четырёх колонок. Ширину задайте `columnSpan(1–4)`, высоту — `rowSpan(1–4)`.

```php
return [
    Stat::make('Заявки сегодня')->value(fn () => Lead::query()->whereDate('created_at', today())->count()),
    Chart::make('Заявки за 30 дней')->bar()->data(fn () => Chart::countByDay(Lead::query()))->columnSpan(3)->rowSpan(2),
    Stat::make('Без ответа')->value(fn () => Lead::query()->whereNull('manager_id')->count())->key('leads-unanswered'),
];
```

Каждый администратор может сам переставить виджеты кнопкой «Настроить». После обновления пакета выполните `php artisan migrate`. Задайте `key()` виджету, если его заголовок меняется, — иначе настройки этого виджета сбросятся. Подробнее — [настройка в админке](../dashboard.md#настройка-в-админке).

## Отчёты

### Как сделать отчёт за период с фильтрами?

Создайте класс-наследник `Page` с методами `filters()` и `widgets()`. Шаблон не нужен — отчёт выводит пакет; заготовку без шаблона создаёт `--report`.

```bash
php artisan fastleo:make-page SalesReport --report   # app/Fastleo/SalesReportPage.php
```

```php
// app/Fastleo/SalesReportPage.php
namespace App\Fastleo;

use App\Models\Lead;
use App\Models\User;
use Fastleo\Fastleo;
use Fastleo\Page;
use Fastleo\Reports\Choice;
use Fastleo\Reports\Filters;
use Fastleo\Reports\Period;
use Fastleo\Widgets\Chart;
use Fastleo\Widgets\Stat;

final class SalesReportPage extends Page
{
    protected static ?string $label = 'Продажи';

    protected static string $icon = 'chart-column';

    protected static ?string $group = 'Отчёты';

    public function filters(): array
    {
        return [
            Period::make('period', 'Период')->default('month'),
            Choice::make('manager', 'Менеджер')
                ->options(fn () => User::query()->where('fastleo_admin', true)->orderBy('name')->pluck('name', 'id')->all())
                ->default(fn () => (string) Fastleo::guard()->id()),
        ];
    }

    public function widgets(Filters $filters): array
    {
        $period = $filters->period('period');
        $leads = fn () => $period->apply(Lead::query(), 'created_at')
            ->when($filters->get('manager'), fn ($query, $manager) => $query->where('manager_id', $manager));

        return [
            Stat::make('Заявок')->value(fn () => $leads()->count()),
            Stat::make('Продано')->value(fn () => $leads()->where('status', 'won')->count()),
            Chart::make('По дням')->columnSpan(4)->series(function () use ($leads, $period) {
                $byDay = $leads()->get()->countBy(fn ($lead) => $lead->created_at->setTimezone(Fastleo::timezone())->toDateString());

                return ['Заявки' => collect($period->days())->mapWithKeys(fn ($day) => [$day => $byDay[$day] ?? 0])->all()];
            }),
        ];
    }
}
```

Адрес — `/fastleo/pages/sales-report`, пункт появится в меню. Фильтры хранятся в адресе, ссылку можно переслать. Подробнее — [страница-отчёт](../reports.md), [фильтры отчёта](../reports.md#фильтры), [значения в виджетах](../reports.md#значения-в-виджетах).

### Как поставить шесть плиток в ряд?

Задайте странице-отчёту `$columns = 6`. Ширина виджетов `columnSpan()` тогда считается от 1 до 6.

```php
// app/Fastleo/SalesReportPage.php
protected static int $columns = 6;

public function widgets(Filters $filters): array
{
    return [
        Stat::make('Заявки')->value(fn () => …),
        Stat::make('Продажи')->value(fn () => …),
        // … ещё четыре плитки
        Chart::make('Динамика')->columnSpan(4)->series(fn () => …),
        Rows::make('По менеджерам')->columnSpan(6)->columns([…])->rows(fn () => […]),
    ];
}
```

Дашборд всегда из четырёх колонок. Подробнее — [шесть плиток в ряд](../reports.md#шесть-плиток-в-ряд).

### Как добавить в отчёт таблицу с итоговой строкой?

Используйте виджет `Rows`: заголовки столбцов, строки и итог — замыканиями.

```php
use Fastleo\Widgets\Rows;

Rows::make('По источникам')->columnSpan(4)
    ->columns(['Источник', 'Заявок', 'Выручка, ₽'])
    ->rows(fn () => $leads()->selectRaw('source, count(*) as total, sum(price) as revenue')->groupBy('source')->get()
        ->map(fn ($row) => [(string) $row->source, (int) $row->total, (float) $row->revenue])->all())
    ->footer(fn () => ['Итого', $leads()->count(), (float) $leads()->sum('price')]),
```

Числа выравниваются вправо и разбиваются пробелами сами; строк не больше 200. Пример с менеджерами — в [FAQ по CRM](crm.md#как-сделать-отчёт-по-менеджерам-за-период). Подробнее — [виджеты отчёта](../reports.md#виджеты).

### Как дать скачать отчёт в CSV?

Готовой кнопки нет. Сделайте маршрут через `Fastleo::routes()`, возьмите значения фильтров у страницы через `Filters::resolve()` и примените их так же, как в `widgets()`. Кнопкой станет плитка `Stat` со ссылкой.

```php
// routes/web.php
use App\Fastleo\SalesReportPage;
use App\Models\Lead;
use Fastleo\Fastleo;
use Fastleo\Reports\Filters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Fastleo::routes(function () {
    Route::get('sales-report/csv', function (Request $request) {
        $page = new SalesReportPage;
        $filters = Filters::resolve($page->filters(), $request);
        $leads = $filters->period('period')->apply(Lead::query(), 'created_at')
            ->when($filters->get('manager'), fn ($query, $manager) => $query->where('manager_id', $manager));

        return response()->streamDownload(function () use ($leads) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Дата', 'Телефон', 'Статус'], ';', escape: '');
            foreach ($leads->lazy() as $lead) {
                fputcsv($out, [$lead->created_at->format('d.m.Y H:i'), $lead->phone, $lead->status->label()], ';', escape: '');
            }
            fclose($out);
        }, 'sales.csv');
    })->name('sales-report-csv');
}, page: SalesReportPage::class);
```

Кнопку добавьте плиткой в `widgets()` отчёта. Ссылка несёт текущие фильтры страницы, поэтому файл совпадёт с тем, что видно на экране:

```php
// app/Fastleo/SalesReportPage.php, в return метода widgets()
Stat::make('Выгрузка')->value('CSV')->url(route('fastleo.pages.sales-report-csv', request()->query())),
```

Подробнее — [выгрузка отчёта](../reports.md#выгрузка-отчёта).

### Как закрыть отчёт от части администраторов?

Переопределите `canAccess()` у страницы: пункт пропадёт из меню, а адрес ответит 403. Свои маршруты отчёта (выгрузка CSV) объявляйте с `page: SalesReportPage::class` — тогда закрыты и они.

```php
// app/Fastleo/SalesReportPage.php
use Illuminate\Contracts\Auth\Authenticatable;

public function canAccess(?Authenticatable $user): bool
{
    return (bool) $user?->is_owner; // is_owner — колонка вашей модели пользователя
}
```

Чтобы менеджер видел в отчёте только свои данные, ограничьте запрос в виджете — скрытый фильтр не защищает. Подробнее — [кому что показывать](../reports.md#кому-что-показывать), [права](access.md).

## Свои страницы

### Как сделать свою страницу в админке?

Создайте класс и шаблон командой, верните данные из `data()` и выведите их в шаблоне в рамке админки.

```bash
php artisan fastleo:make-page Instructions
```

```php
// app/Fastleo/InstructionsPage.php
namespace App\Fastleo;

use App\Models\Lead;
use Fastleo\Page;
use Illuminate\Http\Request;

final class InstructionsPage extends Page
{
    protected static ?string $label = 'Инструкция';

    protected static string $icon = 'book-open';

    protected static ?string $group = 'Помощь';

    public function view(): string
    {
        return 'fastleo.instructions';
    }

    public function data(Request $request): array
    {
        return ['newLeads' => Lead::query()->whereNull('manager_id')->count()];
    }
}
```

```blade
{{-- resources/views/fastleo/instructions.blade.php --}}
<x-fastleo::layouts.app title="Инструкция для менеджеров">
    <x-fastleo::card title="Сейчас без ответа">{{ $newLeads }}</x-fastleo::card>
</x-fastleo::layouts.app>
```

Адрес — `/fastleo/pages/instructions`. Подробнее — [своя страница](../pages.md#своя-страница-админки).

### Как добавить на свою страницу кнопку, которая что-то делает?

Объявите POST-маршрут через `Fastleo::routes()` (вход и CSRF уже есть) и поставьте в шаблоне `<x-fastleo::action-form>` — кнопку с окном подтверждения. Заготовки:

```bash
php artisan fastleo:make-page Tools               # app/Fastleo/ToolsPage.php и resources/views/fastleo/tools.blade.php
php artisan make:job RebuildPrices                # app/Jobs/RebuildPrices.php
```

```php
// routes/web.php
use App\Fastleo\ToolsPage;
use App\Jobs\RebuildPrices;
use Fastleo\Fastleo;
use Illuminate\Support\Facades\Route;

Fastleo::routes(function () {
    Route::post('tools/rebuild-prices', function () {
        RebuildPrices::dispatch();

        return to_route('fastleo.pages.show', 'tools')->with('fastleo.success', 'Пересчёт цен запущен.');
    })->name('tools-rebuild-prices');
}, page: ToolsPage::class);
```

```blade
{{-- resources/views/fastleo/tools.blade.php --}}
<x-fastleo::layouts.app title="Инструменты">
    <x-fastleo::card title="Цены" description="Пересчитать цены всех товаров по курсу">
        <x-fastleo::action-form :action="route('fastleo.pages.tools-rebuild-prices')" confirm="Пересчитать все цены?" :danger="false" button="Пересчитать">
            <x-fastleo::button type="submit" variant="primary" icon="refresh-cw">Пересчитать</x-fastleo::button>
        </x-fastleo::action-form>
    </x-fastleo::card>
    <x-fastleo::flash />
</x-fastleo::layouts.app>
```

`page: ToolsPage::class` переносит на маршрут права страницы: кому страница закрыта (`canAccess()`), тот получит 403 и на кнопке. Подробнее — [кнопки и формы](../pages.md#кнопки-и-формы-на-своей-странице), [компоненты](../theming.md#компоненты-для-своих-шаблонов).

### Как сделать форму на своей странице?

Сделайте обычную HTML-форму с `@csrf` и готовыми классами полей, а значения проверяйте в маршруте `$request->validate()`. Шаблон и маршрут — как в предыдущем вопросе.

```blade
{{-- resources/views/fastleo/tools.blade.php --}}
<x-fastleo::card title="Сменить наценку">
    <form method="POST" action="{{ route('fastleo.pages.tools-markup') }}" class="tools-form">
        @csrf
        <label for="markup">Наценка, %</label>
        <input id="markup" name="markup" type="number" value="{{ old('markup') }}" @class(['fastleo-input', 'fastleo-input-invalid' => $errors->has('markup')])>
        @error('markup') <p class="tools-error">{{ $message }}</p> @enderror
        <x-fastleo::button type="submit" variant="primary">Сохранить</x-fastleo::button>
    </form>
</x-fastleo::card>
<x-fastleo::flash />
```

```php
// routes/web.php, внутри Fastleo::routes(..., page: ToolsPage::class)
Route::post('tools/markup', function (Request $request) {
    $data = $request->validate(['markup' => ['required', 'integer', 'between:0,300']]);
    Product::query()->update(['markup' => $data['markup']]);

    return back()->with('fastleo.success', 'Наценка сохранена.');
})->name('tools-markup');
```

Если нужно просто хранить значения, которые меняет администратор, вместо своей формы сделайте [страницу настроек](../settings.md) — она без кода маршрутов. Подробнее — [кнопки и формы](../pages.md#кнопки-и-формы-на-своей-странице).

## Меню

### Как сгруппировать пункты меню и задать их порядок?

Задайте ресурсам и страницам `$group` и `$position`, а порядок самих групп — в `menu.groups`.

```php
// app/Fastleo/LeadResource.php
protected static ?string $group = 'Работа';
protected static int $position = 10; // меньше — выше

// config/fastleo.php
'menu' => [
    'groups' => ['Работа', 'Клиенты', 'Отчёты', 'Справочники'],
],
```

Группы, которых нет в списке, идут после, по алфавиту. Подробнее — [меню](../configuration.md#меню), [свойства ресурса](../resources.md#свойства).

### Как добавить в меню ссылку на сайт или внешний сервис?

Перечислите ссылки в `menu.links`.

```php
// config/fastleo.php
'menu' => [
    'links' => [
        ['label' => 'Открыть сайт', 'url' => '/', 'icon' => 'globe'],
        ['label' => 'Метрика', 'url' => 'https://metrika.yandex.ru', 'icon' => 'chart-line', 'group' => 'Сервисы', 'position' => 100],
    ],
],
```

Подробнее — [меню](../configuration.md#меню).

### Как убрать «Дашборд» и открывать сразу нужный раздел?

Выключите пункт `menu.dashboard` и укажите стартовую страницу в `home`.

```php
// config/fastleo.php
'home' => 'leads',            // адрес раздела или своей страницы
'menu' => [
    'dashboard' => false,
],
```

Страница дашборда останется по адресу `/fastleo`. Опечатку в `home` покажет `php artisan fastleo:doctor`. Подробнее — [меню](../configuration.md#меню).

### Как сделать меню свёрнутым по умолчанию?

Задайте `menu.collapsed`.

```php
// config/fastleo.php
'menu' => [
    'collapsed' => true, // false — развёрнуто, null — по ширине окна
],
```

Если администратор сам нажмёт «Свернуть меню», его выбор запомнится в браузере и будет важнее настройки. Подробнее — [меню](../configuration.md#меню).

### Как поменять иконку раздела или страницы?

Укажите имя любой иконки из набора [Lucide](https://lucide.dev/icons) в `$icon`.

```php
// app/Fastleo/DoctorResource.php
protected static string $icon = 'stethoscope';
```

В своих шаблонах иконка выводится компонентом `<x-fastleo::icon name="stethoscope" />`. Неизвестное имя даёт исключение со ссылкой на список. Подробнее — [иконки](../configuration.md#иконки).

### Как убрать раздел из меню, оставив его доступным по адресу?

Верните `false` из `inMenu()` ресурса.

```php
// app/Fastleo/LeadCommentResource.php
public static function inMenu(): bool
{
    return false;
}
```

Права проверяются как обычно. Чтобы закрыть раздел целиком, используйте Policy ([права](access.md)). Подробнее — [inMenu()](../resources.md#inmenu).

## Оформление

### Как поменять название, логотип и основной цвет?

Задайте `brand` в `config/fastleo.php`.

```php
// config/fastleo.php
'brand' => [
    'name' => 'Стоматология',
    'logo' => '/images/admin-logo.svg',
    'color' => '#0ea5e9',
],
```

На основном цвете должен читаться белый текст: яркие цвета затемните. Оттенки наведения и тёмной темы пакет посчитает сам. Подробнее — [название, логотип, цвет и тема](../theming.md#название-логотип-цвет-и-тема).

### Как подключить свой CSS и поменять цвета?

Положите файл в `public/css`, подключите его в `css` и переопределите CSS-переменные — для светлой темы и для `.dark`.

```php
// config/fastleo.php
'css' => ['/css/fastleo.css'],
```

```css
/* public/css/fastleo.css */
:root {
    --fl-bg: #f7f9fc;
    --fl-sidebar: #eef2f7;
}

.dark {
    --fl-bg: #0f1115;
    --fl-sidebar: #0b0d11;
}

.queue-size {
    font-size: 2rem;
    font-weight: 650;
}
```

Классы Tailwind из шаблонов сайта не работают — пишите обычный CSS. Подробнее — [свой CSS](../theming.md#свой-css).

### Как включить тёмную тему по умолчанию?

Задайте `theme`.

```php
// config/fastleo.php
'theme' => 'dark', // light, dark или system
```

Администратор может переключить тему в шапке, и его выбор запомнится в браузере. Подробнее — [оформление](../configuration.md#оформление).

### Как изменить шаблон пакета, например страницу входа?

Скопируйте шаблон в `resources/views/vendor/fastleo/` с тем же путём и правьте копию.

```bash
mkdir -p resources/views/vendor/fastleo/auth
cp vendor/camanru/fastleo/resources/views/auth/login.blade.php resources/views/vendor/fastleo/auth/login.blade.php
```

Копия не обновляется вместе с пакетом: после `composer update` сравните её с новой версией. Сначала попробуйте конфиг, свой CSS и тексты интерфейса. Подробнее — [переопределить шаблон](../theming.md#переопределить-шаблон-пакета).

### Как поменять надпись в интерфейсе админки?

Создайте файл перевода сайта с нужным ключом — он заменит фразу пакета.

```php
// lang/vendor/fastleo/ru/auth.php
return [
    'heading' => 'Вход для сотрудников',
];
```

Ключи смотрите в `vendor/camanru/fastleo/resources/lang/ru/*.php`. Подробнее — [тексты интерфейса](../configuration.md#тексты-интерфейса).

## Поиск, время, уведомления, журнал ошибок

### Как задать часовой пояс админки?

Задайте `timezone` — в нём показываются и вводятся даты со временем, считаются дни в фильтрах и графиках.

```php
// config/fastleo.php
'timezone' => 'Europe/Moscow',
```

В базе даты по-прежнему хранятся в поясе сайта (`app.timezone`). Подробнее — [часовой пояс](../configuration.md#часовой-пояс).

### Как сделать, чтобы запись находилась через Ctrl+K?

Отметьте нужные поля ресурса `searchable()` — глобальный поиск ищет по тем же полям, что и строка поиска списка. Раздел можно исключить из поиска.

```php
// app/Fastleo/ClientResource.php
Text::make('name', 'Имя')->searchable(),
Tel::make('phone', 'Телефон')->searchable(),

// app/Fastleo/LeadCommentResource.php — не искать здесь через Ctrl+K
protected static bool $globallySearchable = false;
```

Подробнее — [глобальный поиск](../list.md#глобальный-поиск).

### Как отправить уведомление в колокольчик из своего кода?

Вызовите `Fastleo::notify()` для одного администратора или `Fastleo::notifyAdmins()` для всех — из observer, команды или задачи.

```php
use Fastleo\Fastleo;
use Fastleo\Notifications\FastleoMessage;

// одному администратору (только колокольчик)
Fastleo::notify($user, FastleoMessage::make('Отчёт готов')->url(route('fastleo.pages.show', 'sales-report'))->icon('file-check'));

// всем администраторам: колокольчик, чаты ботов, почта (если включена)
Fastleo::notifyAdmins(FastleoMessage::make('Импорт прайса не прошёл')->body('Поставщик не ответил')->level('danger'));
```

Если у сайта уже есть свои классы уведомлений Laravel, добавьте канал `fastleo` в `via()` и метод `toFastleo()`. Подробнее — [отправить уведомление](../notifications.md#отправить-уведомление), [свой класс уведомления](../notifications.md#свой-класс-уведомления), [копия на почту](../notifications.md#копия-на-почту).

### Как закрыть журнал ошибок от части администраторов или выключить его?

Опишите Gate `viewFastleoLogs` или выключите страницу в конфиге.

```php
// app/Providers/AppServiceProvider.php, в boot()
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewFastleoLogs', fn (User $user) => $user->is_owner);

// config/fastleo.php — убрать страницу совсем
'logs' => ['enabled' => false],
```

В журнале могут быть персональные данные из запросов — открывайте его только доверенным администраторам. Подробнее — [журнал ошибок](../pages.md#журнал-ошибок), [настройки журнала](../configuration.md#журнал-ошибок).
