# Страница-отчёт: фильтры и виджеты

Статистика по менеджерам, продажи за период, заявки по источникам: страница админки без модели, на которой сверху — фильтры (период, менеджер, статус), а под ними — плитки с числами, график и таблицы, пересчитанные по выбранным фильтрам. Это обычная [своя страница](pages.md) (`Fastleo\Page`), только вместо Blade-шаблона вы описываете `filters()` и `widgets()`.

```php
namespace App\Fastleo;

use App\Models\Lead;
use App\Models\Manager;
use Fastleo\Fastleo;
use Fastleo\Page;
use Fastleo\Reports\Choice;
use Fastleo\Reports\Filters;
use Fastleo\Reports\Period;
use Fastleo\Widgets\Chart;
use Fastleo\Widgets\Rows;
use Fastleo\Widgets\Stat;

final class StatisticsPage extends Page
{
    protected static ?string $label = 'Статистика';
    protected static string $icon = 'chart-column';

    public function filters(): array
    {
        return [
            Period::make('period', 'Период')->default(fn () => ['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]),
            Choice::make('manager', 'Менеджер')->options(fn () => Manager::query()->pluck('name', 'id')->all()),
        ];
    }

    public function widgets(Filters $filters): array
    {
        $period = $filters->period('period');
        $leads = fn () => $period->apply(Lead::query(), 'created_at')
            ->when($filters->get('manager'), fn ($query, $manager) => $query->where('manager_id', $manager));

        return [
            Stat::make('Заявок')->value(fn () => $leads()->count()),
            Stat::make('Продано')->value(fn () => $leads()->where('status', 'sold')->count()),
            // Ось — все дни периода по порядку; дни без записей — нули. Дни считаются в поясе админки, как и сам период.
            Chart::make('По дням')->columnSpan(4)->series(function () use ($leads, $period) {
                $byDay = fn ($query) => $query->get()->countBy(fn ($lead) => $lead->created_at->setTimezone(Fastleo::timezone())->toDateString());
                $all = $byDay($leads());
                $sold = $byDay($leads()->where('status', 'sold'));

                return [
                    'Заявки' => collect($period->days())->mapWithKeys(fn ($day) => [$day => $all[$day] ?? 0])->all(),
                    'Продажи' => collect($period->days())->mapWithKeys(fn ($day) => [$day => $sold[$day] ?? 0])->all(),
                ];
            }),
            // Строки и итог считаются по тем же фильтрам, что и плитки.
            Rows::make('Менеджеры')->columnSpan(4)
                ->columns(['Менеджер', 'Заявок', 'Выручка'])
                ->rows(fn () => Manager::query()->get()->map(fn ($manager) => [
                    $manager->name,
                    $leads()->where('manager_id', $manager->id)->count(),
                    (float) $leads()->where('manager_id', $manager->id)->sum('price'),
                ])->all())
                ->footer(fn () => ['Итого', $leads()->count(), (float) $leads()->sum('price')]),
        ];
    }
}
```

Страница открывается по адресу `/fastleo/pages/statistics` и появляется в меню, как любая [своя страница](pages.md): `$label`, `$icon`, `$group`, `$position`, `canAccess()`.

## Фильтры

Фильтры описываются в `filters()`. Выбор применяется сразу; значения лежат в адресе (`?filter[period][from]=2026-03-01&filter[manager]=3`), поэтому страницу с выбранными фильтрами можно сохранить в закладки или переслать.

| Фильтр | Что делает |
|---|---|
| `Period::make('period', 'Период')` | Даты «с … по …» (включительно) и кнопки готовых периодов: «Сегодня», «Вчера», «7 дней», «Месяц» (с 1-го числа), «Квартал». Дни считаются в [часовом поясе админки](configuration.md#часовой-пояс) |
| `Choice::make('manager', 'Менеджер')` | Список «Все» и варианты: `->options([1 => 'Анна'])` или замыкание |

Общее:

- `->default(...)` — значение, пока администратор ничего не выбирал. У `Period` — имя готового периода (`'month'`; без `default()` — `'today'`) или замыкание, возвращающее `['from' => 'ГГГГ-ММ-ДД', 'to' => 'ГГГГ-ММ-ДД']`; у `Choice` — ключ варианта или замыкание (например, «менеджер — я»: `fn () => (string) auth()->id()`). Замыкание вычисляется при каждом показе страницы.
- Выбрали «Все» у списка или стёрли обе даты — в адресе остаётся пустой `filter[имя]=`, умолчание не применяется, а период открыт (без границ). Кнопка периода или ссылка на страницу без фильтров возвращает умолчание.
- `Period::make(...)->presets(['today', 'month'])` оставляет часть кнопок. Ключи готовых периодов: `today` (сегодня), `yesterday` (вчера), `week` (7 дней), `month` (с 1-го числа), `quarter` (квартал); они же годятся для `default()`. Незнакомый ключ молча отбрасывается (в `presets()`) или даёт «сегодня» (в `default()`).
- Значение не из вариантов, несуществующая дата (`2026-02-30`), массив вместо строки не применяются, ошибки нет. Если «с» позже «по», даты меняются местами.

## Значения в виджетах

`widgets(Filters $filters)` получает значения; виджеты берут их в замыканиях.

| Метод | Что возвращает |
|---|---|
| `$filters->period('period')` | `PeriodValue`: `from` и `to` (`CarbonImmutable` или `null`), `fromDate()`/`toDate()` строкой, `days()` — список дат периода (не больше 366) |
| `$period->apply($query, 'created_at')` | Ограничивает запрос периодом по колонке даты со временем (границы переводятся в часовой пояс сайта, в котором лежит база) |
| `$filters->get('manager')` | Ключ выбранного варианта или `null` («Все») |

Значения безопасны для запроса: даты проверены, вариант взят из списка. Но колонку и условия в запросе выбираете вы — не подставляйте в них ничего из адреса.

## Виджеты

Подходят все [виджеты дашборда](dashboard.md): `Stat` (число), `Chart`, `Table` (последние записи раздела) и свои. Размер — `columnSpan()` (1–4, сетка из четырёх колонок; 1–6 при шести колонках, ниже) и `rowSpan()`. Виджет, который упал при расчёте, показывает «Не удалось загрузить», остальные работают; `visible(fn ($user) => …)` скрывает виджет от части администраторов. Для отчётов добавлено:

- **`Rows`** — таблица со своими строками и итоговой строкой: `columns([...])`, `rows(fn () => [[…], …])`, `footer(fn () => [...])`. Значения — строки, числа и `null`; числа выравниваются вправо и разбиваются пробелами (дробные — с двумя знаками, целые `float` — без них), текст экранируется. Не больше 200 строк.
- **`Chart::series(fn () => ['Заявки' => ['01.03' => 4, …], 'Продажи' => […]])`** — несколько линий (столбцов) на одном графике, до восьми, с легендой. Подписи точек объединяются в порядке появления, пропущенное — ноль. Подписи вида `2026-03-08` (так отдаёт `$period->days()`) на оси показываются как `08.03`, а в подсказке — `08.03.2026`; линии гладкие, но не выходят за значения точек.

## Шесть плиток в ряд

Отчёт с рядом небольших чисел («Заявки», «Продажи», «Прибыль»…) выглядит лучше, когда плиток шесть в ряд, а не четыре. Включается в странице:

```php
final class SalesPage extends Page
{
    protected static int $columns = 6;

    public function widgets(Filters $filters): array
    {
        return [
            Stat::make('Заявки')->value(fn () => …),       // одна шестая строки
            Chart::make('Динамика')->columnSpan(4),          // четыре шестых
            Rows::make('По менеджерам')->columnSpan(6),      // вся строка
        ];
    }
}
```

`$columns` — 4 (по умолчанию) или 6; любое другое значение считается четырьмя. При шести колонках `columnSpan()` принимает 1–6, при четырёх виджет шире сетки занимает всю строку. На планшете плитки идут по две в ряд, на телефоне — в столбик. Дашборд всегда из четырёх колонок: виджет с `columnSpan(6)` занимает на нём всю строку.

## Кому что показывать

Отчёт открывается всем администраторам, пока страница не закрыта `canAccess()`. «Менеджер видит только свою строку» — условие в данных виджета, а не в интерфейсе:

```php
// is_admin — флаг вашей модели пользователя (в пакете такого свойства нет).
$mine = ! auth()->user()->is_admin ? auth()->id() : null;

Rows::make('Менеджеры')->rows(fn () => Manager::query()->when($mine, fn ($query) => $query->whereKey($mine))->get()->map(…)->all());
```

Не полагайтесь на то, что скрытый фильтр «не выбрать»: администратор может подставить любое значение в адрес. Права на данные проверяйте в самом запросе виджета.

## Выгрузка отчёта

Готовой кнопки «Скачать» у отчёта нет: сделайте маршрут сайта через `Fastleo::routes()` (с защитой админки и CSRF) и отдайте CSV теми же запросами, взяв значения фильтров теми же, что у страницы (с её умолчаниями): `$filters = Filters::resolve((new StatisticsPage)->filters(), request()); $period = $filters->period('period');` (`Fastleo\Reports\Filters`). Отдельный `new Period('period')` не знает умолчания страницы и без `filter[period]` в адресе возьмёт «сегодня».
