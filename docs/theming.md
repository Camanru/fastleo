# Оформление

Внешний вид админки меняется без сборки: название, логотип и основной цвет — в конфиге, остальное — своим CSS-файлом. Стили и скрипты пакета собраны заранее, сайту npm не нужен.

## Название, логотип, цвет и тема

```php
// config/fastleo.php
'brand' => [
    'name' => 'Стоматология',            // null — config('app.name')
    'logo' => '/images/admin-logo.svg',  // null — значок и название
    'color' => '#0ea5e9',                // основной цвет кнопок и ссылок
],

'theme' => 'system',                     // light, dark или system
```

- **Цвет** — HEX (`#rgb`, `#rrggbb`, `#rrggbbaa`). Оттенки наведения, выделения, фокуса и осветление для тёмной темы пакет считает из него сам. Белый текст на нём должен читаться (контраст не меньше 4,5 : 1) — слишком яркие цвета затемните.
- **Тема** — значение по умолчанию. Администратор переключает тему в шапке (светлая, тёмная, как в системе), выбор запоминается в браузере.

Все ключи и значения по умолчанию — в [настройках](configuration.md#оформление).

## Свой CSS

Файлы из `css` подключаются после стилей пакета, поэтому их правила главнее:

```php
// config/fastleo.php
'css' => ['/css/fastleo.css'],
```

Цвета админки — CSS-переменные. Переопределите нужные, и они сменятся везде:

```css
/* public/css/fastleo.css */
:root {
    --fl-bg: #f7f9fc;       /* фон страницы */
    --fl-sidebar: #eef2f7;  /* фон меню */
}

.dark {
    --fl-bg: #0f1115;       /* то же в тёмной теме */
    --fl-sidebar: #0b0d11;
}
```

Переменная, заданная в `:root`, действует и в тёмной теме: у `:root` и `.dark` одинаковая сила, а файл сайта подключается позже, поэтому он перебивает тёмное значение пакета. Задавая переменную в `:root`, задайте её и в `.dark { … }` — иначе в тёмной теме получится светлый фон под светлым текстом.

| Переменная | Что красит |
|---|---|
| `--fastleo-primary` | основной цвет (задаётся `brand.color`) |
| `--fl-bg`, `--fl-sidebar` | фон страницы и меню |
| `--fl-surface`, `--fl-surface-2`, `--fl-sunken` | карточки, вторичный фон, утопленные области |
| `--fl-text`, `--fl-text-2`, `--fl-text-3` | основной, вторичный и бледный текст |
| `--fl-border`, `--fl-border-strong` | рамки |
| `--fl-success`, `--fl-warning`, `--fl-danger`, `--fl-info` и `*-soft` | цвета статусов и их фон |

Тёмная тема — класс `.dark` на `<html>`: всё, что задано внутри `.dark { … }`, действует только в ней.

**Классы Tailwind в своём CSS и шаблонах не работают**: CSS пакета собран заранее и содержит только классы из шаблонов пакета. Пишите обычный CSS или берите компоненты и готовые классы ниже.

## Компоненты для своих шаблонов

В [своих страницах](pages.md), [виджетах](dashboard.md) и [своих полях](fields/custom.md) используйте компоненты пакета — они уже оформлены и работают в обеих темах:

```blade
<x-fastleo::layouts.app title="Отчёт по заявкам">
    <x-slot:actions>
        <x-fastleo::button variant="primary" icon="download" :href="route('reports.export')">Скачать</x-fastleo::button>
    </x-slot:actions>

    <x-fastleo::card title="Всего заявок" description="За последние 30 дней">
        {{ $total }} <x-fastleo::badge tone="success">растёт</x-fastleo::badge>
    </x-fastleo::card>
</x-fastleo::layouts.app>
```

| Компонент | Для чего |
|---|---|
| `<x-fastleo::layouts.app title="…" description="…">` | рамка админки: меню, шапка, заголовок; слот `actions` — кнопки справа от заголовка |
| `<x-fastleo::card title="…" description="…">` | карточка; слот `action` — действие в правом верхнем углу |
| `<x-fastleo::button variant="…" icon="…" :href="…" size="…">` | кнопка или ссылка-кнопка: `primary`, `secondary`, `ghost`, `danger`, `danger-soft`, `icon`, `icon-danger`, `dashed`; размеры `sm`, `md`, `lg` |
| `<x-fastleo::badge tone="…">` | статус: `success`, `warning`, `danger`, `info`, `neutral` |
| `<x-fastleo::icon name="…" />` | иконка [Lucide](https://lucide.dev/icons) |
| `<x-fastleo::action-form :action="…" method="DELETE" :confirm="…">` | кнопка изменяющего действия с CSRF и окном подтверждения |
| `<x-fastleo::flash />` | сообщение после действия |

Готовые классы: `fastleo-card` (карточка), `fastleo-input` и `fastleo-input-invalid` (поле ввода и поле с ошибкой), `fastleo-prose` (оформленный текст).

## Переопределить шаблон пакета

Laravel сначала ищет шаблоны пакета в папке сайта `resources/views/vendor/fastleo/`. Чтобы изменить шаблон, скопируйте его туда с тем же путём:

```bash
mkdir -p resources/views/vendor/fastleo/auth
cp vendor/camanru/fastleo/resources/views/auth/login.blade.php resources/views/vendor/fastleo/auth/login.blade.php
```

Скопированный шаблон перестаёт обновляться вместе с пакетом: после `composer update` сравните его с новой версией. Поэтому сначала попробуйте конфиг, свой CSS и [тексты интерфейса](configuration.md#тексты-интерфейса) — этого обычно хватает. Классы Tailwind, которых нет в шаблонах пакета, в скопированном шаблоне тоже не сработают.

## Тексты и иконки

- Любую фразу интерфейса можно заменить файлом `lang/vendor/fastleo/ru/*.php` ([настройки](configuration.md#тексты-интерфейса)).
- Иконки разделов и страниц — любые из набора [Lucide](https://lucide.dev/icons): `protected static string $icon = 'stethoscope';` ([ресурсы](resources.md)).
