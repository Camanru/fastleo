# Свои страницы и журнал ошибок

## Своя страница админки

Страница без модели: отчёт, инструкция для редакторов, панель управления. Класс наследует `Fastleo\Page`, лежит рядом с ресурсами, а шаблон — в `resources/views` сайта. Оба файла создаёт `php artisan fastleo:make-page Report` (класс `ReportPage`, шаблон `resources/views/fastleo/report.blade.php`).

```php
namespace App\Fastleo;

use Illuminate\Http\Request;

final class ReportPage extends \Fastleo\Page
{
    protected static ?string $label = 'Отчёт по заявкам';
    protected static string $icon = 'chart-bar';
    protected static ?string $group = 'Отчёты';

    public function view(): string
    {
        return 'fastleo.report';    // resources/views/fastleo/report.blade.php
    }

    public function data(Request $request): array
    {
        return ['total' => Lead::count()];
    }
}
```

```blade
{{-- resources/views/fastleo/report.blade.php --}}
<x-fastleo::layouts.app title="Отчёт по заявкам">
    <x-fastleo::card title="Всего заявок">{{ $total }}</x-fastleo::card>
</x-fastleo::layouts.app>
```

Страница открывается по адресу `/fastleo/pages/report` (`ReportPage` → `report`) и появляется в меню.

| Свойство / метод | По умолчанию | Что задаёт |
|---|---|---|
| `$label` | имя класса без «Page» | Название в меню |
| `$icon` | `'file-text'` | Иконка Lucide |
| `$group` | `null` | Группа меню |
| `$position` | `0` | Порядок в группе |
| `$slug` | kebab имени класса без «Page» | Часть адреса. Адрес `softnetica` занят встроенным разделом |
| `view()` | обязательно | Blade-шаблон |
| `data(Request)` | `[]` | Данные для шаблона |
| `canAccess($user)` | всем администраторам | Закрыть страницу: не видна в меню, ответ 403 |
| `isAvailable()` | `true` | Страница существует только при условии: если `false`, её нет в меню и поиске, а адрес отвечает 404 |

Для шаблонов есть компоненты:

- `<x-fastleo::layouts.app title="…" description="…" :count="12">` — рамка админки: меню, шапка и заголовок страницы. Кнопки справа от заголовка передаются слотом `<x-slot:actions>…</x-slot:actions>`;
- `<x-fastleo::card title="…" description="…">` — карточка (слот `<x-slot:action>` — действие в правом верхнем углу);
- `<x-fastleo::button variant="primary" icon="plus" :href="…">` — кнопка или ссылка-кнопка. Варианты: `primary`, `secondary` (по умолчанию), `ghost`, `danger`, `danger-soft`, `icon`, `icon-danger` (только иконка, подпись — в `<span class="sr-only">`). Размеры `size`: `md`, `sm`, `lg`;
- `<x-fastleo::badge tone="success">Опубликована</x-fastleo::badge>` — статус. Тона: `success`, `warning`, `danger`, `info`, `neutral`; смысл всегда дублируйте текстом;
- `<x-fastleo::icon name="house" />` — иконка [Lucide](https://lucide.dev/icons);
- `<x-fastleo::flash />` — сообщение после действия (всплывает снизу справа).

CSS админки собран заранее, поэтому классы Tailwind из ваших шаблонов в него не попадут. Для оформления берите компоненты пакета и готовые классы: `fastleo-input` (поле ввода), `fastleo-input-invalid` (поле с ошибкой), `fastleo-card` (карточка), `fastleo-prose` (текст из Markdown). Свои стили подключайте через `fastleo.css` в [настройках](configuration.md#оформление): цвета админки — CSS-переменные `--fl-bg`, `--fl-surface`, `--fl-text`, `--fl-border` и другие (в тёмной теме значения заменяются сами).

### Кнопки и формы на своей странице

Маршруты сайта внутри админки объявляются через `Fastleo::routes()`: они получают префикс и домен админки, вход, проверку доступа, CSRF, русский язык и заголовки безопасности.

```php
// routes/web.php
use Fastleo\Fastleo;

Fastleo::routes(function () {
    Route::post('report/run', RunReportController::class)->name('report-run');
});
```

Адрес — `/fastleo/pages/report/run`, имя — `fastleo.pages.report-run`. В форме используйте `@csrf` и `route('fastleo.pages.report-run')`. Берите маршруты длиннее одного сегмента (`report/run`), чтобы не пересечься со страницей `/fastleo/pages/{страница}`.

Страница с фильтрами (период, список) и виджетами вместо своего шаблона — [reports.md](reports.md).

## Журнал ошибок

Пункт «Журнал ошибок» в меню (группа «Система») показывает записи `storage/logs/*.log` от новых к старым, по 50 на страницу.

- Фильтр уровня: «Ошибки» (по умолчанию), «Предупреждения и ошибки», «Все записи»; поиск по тексту.
- Файл читается **с конца порциями**: журнал в сотни мегабайт открывается так же быстро, как маленький. За один раз просматривается не больше 2 МБ; если подходящих записей в этой части нет, страница предлагает продолжить.
- Если в папке несколько журналов (`laravel.log`, `worker.log`), появляется выбор файла. Открываются только файлы `*.log` из папки журнала приложения (папка канала `single`); имя из адреса принимается лишь при точном совпадении — выйти за папку нельзя.
- «Очистить журнал» (POST, с подтверждением) обнуляет файл, не удаляя его, чтобы приложение продолжило писать с теми же правами.
- Подробности (трассировка) раскрываются под записью, длинные — обрезаются.

Закрыть журнал для части администраторов можно Gate:

```php
Gate::define('viewFastleoLogs', fn (User $user) => $user->is_owner);
```

Выключить страницу совсем:

```php
// config/fastleo.php
'logs' => ['enabled' => false],
```

Журнал ошибок сайта может содержать пароли и персональные данные из запросов — открывайте его только доверенным администраторам.
