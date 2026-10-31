# Настройки

Файл `config/fastleo.php` появляется после `php artisan fastleo:install` (или `php artisan vendor:publish --tag=fastleo-config`). Без него действуют значения по умолчанию из пакета.

После изменения настроек на боевом сайте выполните `php artisan config:cache`.

## Адрес

```php
'path' => 'fastleo',
'domain' => null,
'middleware' => ['web'],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `path` | `'fastleo'` | Префикс адресов: `https://site.ru/fastleo`. Для `'admin'` — `https://site.ru/admin` |
| `domain` | `null` | Отдельный домен админки, например `'admin.site.ru'`. `null` — на домене сайта |
| `middleware` | `['web']` | Группы middleware для всех страниц админки. Группа `web` нужна: сессии, cookie, CSRF |

Ссылки внутри админки строятся через `route('fastleo.*')`, поэтому смена адреса ничего не ломает.

```php
// Админка на отдельном домене: https://admin.site.ru/
'path' => '',
'domain' => 'admin.site.ru',
```

## Вход

```php
'auth' => [
    'provider' => 'users',
    'remember_days' => 30,
    'reset_minutes' => 60,
    'two_factor_required' => false,
    'new_login_mail' => true,
    'logins_keep_days' => 90,
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `auth.provider` | `'users'` | Провайдер пользователей из `config/auth.php`. Его модель реализует `Fastleo\Auth\FastleoUser` |
| `auth.remember_days` | `30` | Сколько дней держится вход с галочкой «Запомнить меня» |
| `auth.reset_minutes` | `60` | Сколько минут действует ссылка «Забыли пароль?» ([подробнее](auth.md)) |
| `auth.two_factor_required` | `false` | Требовать двухфакторную защиту от всех администраторов ([подробнее](auth.md#двухфакторная-защита-2fa)) |
| `auth.new_login_mail` | `true` | Писать администратору о входе с нового устройства |
| `auth.logins_keep_days` | `90` | Сколько дней хранится история входов в профиле |

Пакет сам регистрирует guard `fastleo` (драйвер `session`). Если описать guard `fastleo` в `config/auth.php` сайта, пакет его не трогает — тогда `auth.provider` и `auth.remember_days` не действуют, а вход, миграция `fastleo_admin` и команды `fastleo:user`, `fastleo:install` работают с провайдером этого guard:

```php
// config/auth.php
'guards' => [
    'fastleo' => [
        'driver' => 'session',
        'provider' => 'admins',
        'remember' => 60 * 24 * 7, // минуты
    ],
],
```

## Администраторы

```php
'users' => [
    'resource' => Fastleo\Resources\UserResource::class,
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `users.resource` | `Fastleo\Resources\UserResource::class` | Встроенный раздел «Администраторы». Свой класс-наследник или `null` — без раздела ([подробнее](auth.md#администраторы)) |

## Журнал изменений

```php
'activity' => [
    'enabled' => false,
    'keep_days' => 30,
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `activity.enabled` | `false` | Вести журнал изменений, сделанных в админке; `true` — включить ([подробнее](activity.md)) |
| `activity.keep_days` | `30` | Сколько дней хранятся записи; чистит `fastleo:prune` |

## Журнал ошибок

```php
'logs' => [
    'enabled' => true,
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `logs.enabled` | `true` | Страница «Журнал ошибок» ([подробнее](pages.md#журнал-ошибок)) |

## Карта сайта

```php
'sitemap' => [
    'enabled' => false,
    'cache_minutes' => 60,
    'per_file' => 50000,
    'urls' => [],
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `sitemap.enabled` | `false` | Публичная карта `/sitemap.xml` ([подробнее](seo.md#карта-сайта)) |
| `sitemap.cache_minutes` | `60` | Сколько хранить готовую карту; `0` — не хранить |
| `sitemap.per_file` | `50000` | Адресов в одном файле карты (не больше 50 000); больше — `/sitemap.xml` становится индексом файлов `/sitemap-N.xml` |
| `sitemap.urls` | `[]` | Свои адреса в карте: `'/contacts'` или `['loc' => …, 'lastmod' => …]` |

## Чистка HTML редактора

Поле [RichText](fields/rich-text.md) при сохранении оставляет структуру текста, остальное вырезает. Здесь сайт разрешает больше.

```php
'rich_text' => [
    'tags' => [],
    'attributes' => [],
    'iframe_hosts' => [],
    'styles' => false,
    'content_css' => [],
    'content_style' => null,
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `rich_text.tags` | `[]` | Дополнительные теги: `['div', 'span']` (теги, удаляемые вместе с содержимым — `script`, `style`, `form`, `svg` и другие, — разрешить нельзя) |
| `rich_text.attributes` | `[]` | Дополнительные атрибуты на всех разрешённых тегах, точные имена: `['class', 'role', 'align', 'data-widget']`; шаблонов вроде `data-*` нет; `on*`, директивы JS-фреймворков (`x-`, `hx-`, `v-`, `ng-`: они исполняют код, в том числе Alpine самой админки), `srcdoc` и `style` (для него — `styles`) нельзя |
| `rich_text.iframe_hosts` | `[]` | Начала адресов плееров для `iframe` в виде `https://хост/путь/` или `https://хост/путь?` — к YouTube, Rutube и VK |
| `rich_text.styles` | `false` | `true` — оставлять `style` целиком вместо одного `text-align`; значения с `url()`, `expression`, `javascript:`, `@import`, экранированием `\` и `position: fixed` убираются всегда |
| `rich_text.content_css` | `[]` | Таблицы стилей сайта для области редактирования — `['/css/app.css']` или `https://хост/путь.css`; адреса `//хост`, `http://` и с кавычками нельзя. Редактор видит текст так же, как посетитель |
| `rich_text.content_style` | `null` | Свои правила CSS для области редактирования, например `'img { max-height: 4rem; }'` (сайтовые логотипы без стилей сайта выходят огромными); `</` в строке нельзя |

Недопустимое значение — ошибка при сохранении и показе текста, `php artisan fastleo:doctor` показывает её сразу. Он же проверяет первые 500 записей каждого раздела с RichText и предупреждает, у скольких при сохранении пропадут теги, атрибуты и плееры.

## Закрытие сайта

```php
'closing' => [
    'enabled' => false,
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `closing.enabled` | `false` | Страница «Закрытие сайта» в настройках: пока на ней стоит галочка, посетители видят «Сайт временно не работает» ([подробнее](site-closing.md)) |

## Уведомления

```php
'notifications' => [
    'keep_days' => 90,
    'bots' => null, // включить: Fastleo\Notifications\NotificationBotResource::class
    'mail' => false,
],

'messengers' => [
    'max' => [
        'url' => 'https://platform-api2.max.ru',
        'certificate' => null,
    ],
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `notifications.keep_days` | `90` | Сколько дней хранятся уведомления колокольчика; чистит `fastleo:prune` |
| `notifications.mail` | `false` | Дублировать `Fastleo::notifyAdmins()` письмом каждому администратору с адресом ([подробнее](notifications.md#копия-на-почту)) |
| `notifications.bots` | `null` | Раздел «Уведомления»: боты Telegram и Max для чатов администраторов; `null` — раздела нет, включить — `Fastleo\Notifications\NotificationBotResource::class` или свой наследник ([подробнее](notifications.md#боты-telegram-и-max)) |
| `messengers.max.url` | `https://platform-api2.max.ru` | Адрес Bot API Max |
| `messengers.max.certificate` | `null` | Сертификат для запросов к API Max; `null` — сертификат Минцифры из пакета |

## Публикации

```php
'publishing' => [
    'channels' => null,     // включить: Fastleo\Publishing\ChannelResource::class
    'publications' => null, // включить: Fastleo\Publishing\PublicationResource::class
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `publishing.channels` | `null` | Раздел «Каналы» для публикаций в Telegram и Max; `null` — раздела нет, включить — `Fastleo\Publishing\ChannelResource::class` или свой наследник ([подробнее](publishing.md)) |
| `publishing.publications` | `null` | Раздел «Публикации»: посты и журнал отправки; `null` — раздела нет, включить — `Fastleo\Publishing\PublicationResource::class` или свой наследник |

## SoftNetica

```php
'softnetica' => [
    'enabled' => true,
    'url' => 'https://panel.softnetica.com',
    'cabinet_hosts' => ['my.softnetica.com'],
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `softnetica.enabled` | `true` | `false` — убрать подключение к SoftNetica целиком ([подробнее](softnetica.md)) |
| `softnetica.url` | `https://panel.softnetica.com` | Адрес панели SoftNetica; только `https` (`http` — при `APP_ENV=local`) |
| `softnetica.cabinet_hosts` | `['my.softnetica.com']` | Хосты кабинета, на которые разрешены ссылки из ответа панели |

## Оформление

```php
'brand' => [
    'name' => null,
    'logo' => null,
    'color' => '#4f46e5',
],

'theme' => 'system',

'css' => [],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `brand.name` | `null` | Название в шапке и заголовке вкладки. `null` — `config('app.name')` |
| `brand.logo` | `null` | Адрес картинки логотипа, например `'/images/logo.svg'`. `null` — значок и название |
| `brand.color` | `'#4f46e5'` | Основной цвет кнопок и ссылок в HEX: `#rgb`, `#rrggbb` или `#rrggbbaa`. Другое значение заменяется цветом по умолчанию. Белый текст на этом цвете должен читаться (контраст не меньше 4,5 : 1): подходят индиго `#4f46e5`, изумрудный `#047857`, оранжевый `#c2410c`; яркие `#22c55e` и `#f97316` затемните. Оттенки наведения, фона и фокуса и осветление в тёмной теме считаются из него сами |
| `theme` | `'system'` | Тема по умолчанию: `'light'`, `'dark'` или `'system'` (как в системе). Администратор переключает тему в шапке, выбор запоминается в браузере |
| `css` | `[]` | Адреса дополнительных стилей сайта; подключаются после стилей пакета |

Боковое меню администратор сворачивает кнопкой «Свернуть меню» внизу (остаются иконки с подсказками); на экранах уже 1280 px оно свёрнуто по умолчанию. Выбор запоминается в браузере.

Пример:

```php
'brand' => [
    'name' => 'Стоматология',
    'logo' => '/images/admin-logo.svg',
    'color' => '#0ea5e9',
],

'theme' => 'light',

'css' => ['/css/fastleo.css'],
```

Стили пакета собраны заранее, поэтому классы Tailwind, которых нет в шаблонах пакета, в сайте не работают. Свои правки оформления пишите обычным CSS в файле из `css`. Основной цвет доступен как CSS-переменная `--fastleo-primary`:

```css
/* public/css/fastleo.css */
a:hover {
    color: var(--fastleo-primary);
}
```

## Разделы

```php
'resources' => [
    'path' => app_path('Fastleo'),
    'namespace' => 'App\\Fastleo',
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `resources.path` | `app_path('Fastleo')` | Папка с классами ресурсов. Подпапки тоже просматриваются |
| `resources.namespace` | `'App\Fastleo'` | Namespace этой папки (по PSR-4: `app/Fastleo/Shop/ProductResource.php` → `App\Fastleo\Shop\ProductResource`) |

На боевом сайте список разделов кешируется командой `php artisan fastleo:cache` (входит в `php artisan optimize`) — [resources.md](resources.md#кеш-списка-разделов).

## Меню

```php
'menu' => [
    'groups' => ['Контент', 'Справочники'],
    'links' => [
        ['label' => 'Открыть сайт', 'url' => '/', 'icon' => 'globe'],
        ['label' => 'Метрика', 'url' => 'https://metrika.yandex.ru', 'icon' => 'chart-line', 'group' => 'Сервисы', 'position' => 100],
    ],
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `menu.groups` | `[]` | Порядок групп меню — названия как в `$group` ресурсов. Группы, которых нет в списке, идут после, по алфавиту |
| `menu.links` | `[]` | Свои ссылки в меню: `label` и `url` обязательны; `icon` (по умолчанию `link`), `group`, `position` (целое число) — как у ресурсов. Неизвестная иконка — ошибка на всех страницах админки |
| `menu.dashboard` | `true` | Показывать пункт «Дашборд» в меню. `false` — пункта нет (страница остаётся по адресу `/fastleo`); нужно сайтам, где работа идёт в разделах, а не на дашборде |
| `menu.collapsed` | `null` | Меню до выбора пользователя: `null` — по ширине окна (узкое — свёрнуто), `true` — свёрнуто, `false` — развёрнуто. Кнопка «Свернуть меню» запоминает выбор в браузере и перебивает настройку |
| `home` | `null` | Стартовая страница: адрес раздела или своей страницы (`'leads'`), куда ведут вход в админку и логотип. `null` или раздел, которого нет или который закрыт Policy, — дашборд; `fastleo:doctor` предупреждает об опечатке |

Сверху меню — «Дашборд» и пункты без группы, затем группы. Внутри группы пункты идут по `position`, при равной позиции — по алфавиту. Разделы, у которых Policy запрещает `viewAny`, в меню не показываются.

## Список записей

```php
'list' => [
    'per_page' => 25,
    'per_page_options' => [25, 50, 100],
    'max_all' => 1000,
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `list.per_page` | `25` | Записей на странице списка; ресурс может задать свой `$perPage` |
| `list.per_page_options` | `[25, 50, 100]` | Варианты «На странице» под списком; ресурс может задать свой `$perPageOptions`; `[]` — выбора нет. Числа больше `list.max_all` отбрасываются |
| `list.max_all` | `1000` | Сколько записей самое большее показывает кнопка «Все»; столько же можно выбрать для [массового действия](actions.md) |

## Импорт

```php
'import' => [
    'disk' => 'local',
    'max_size' => 10240,
    'max_rows' => 50000,
    'keep_days' => 30,
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `import.disk` | `'local'` | Диск для загруженного файла до конца импорта. Не публичный! Если очередь работает на другом сервере — общий диск (например, `s3`) |
| `import.max_size` | `10240` | Наибольший размер файла, КБ |
| `import.max_rows` | `50000` | Сколько строк файла обрабатывается самое большее |
| `import.max_unpacked` | `200` | Наибольший размер xlsx после распаковки, МБ: защита от «архивной бомбы» (маленький файл, который разворачивается в гигабайты) |
| `import.keep_days` | `30` | Сколько дней хранятся отчёты об импорте |

Подробно — [import-export.md](import-export.md#импорт).

## Связи

```php
'relations' => [
    'preload' => 100,
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `relations.preload` | `100` | Сколько вариантов связи ([BelongsTo](fields/belongs-to.md), [BelongsToMany](fields/belongs-to-many.md)) выводится прямо в странице. Если записей больше, список ищет по серверу по мере ввода |

## Файлы

```php
'files' => [
    'disk' => 'public',
    'root' => 'uploads',
    'extensions' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'doc', 'docx', /* … */ 'zip'],
    'images' => ['png', 'jpg', 'jpeg', 'gif', 'webp'],
    'max_size' => null,
    'max_pixels' => 32_000_000,
],

'images' => [
    'max_width' => 2560,
    'quality' => 90,
    'presets' => [],
    'variant_quality' => 80,
    'watermark' => [
        'path' => null,
        'position' => 'bottom-right',
        'opacity' => 50,
        'size' => 20,
    ],
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `files.disk` | `'public'` | Диск Laravel Storage, на котором работает [файловый менеджер](files.md). Нужен диск с публичными адресами: `public`, `s3`, `r2` |
| `files.root` | `'uploads'` | Папка на диске, внутри которой работает менеджер. Выше неё админка ничего не видит и не меняет. Не может быть пустой или `fastleo` (там служебные копии) |
| `files.extensions` | картинки, документы, `mp3`, `mp4`, `webm`, `zip` | Какие файлы можно загружать. Исполняемые и активные форматы (`php`, `phtml`, `phar`, `html`, `svg`, `js`, `xml`, `htaccess` и другие) запрещены всегда, даже если вписать их сюда |
| `files.images` | `png`, `jpg`, `jpeg`, `gif`, `webp` | Какие из разрешённых файлов — картинки. Картинка при загрузке проверяется и пересохраняется: поворот по EXIF, без метаданных (в том числе координат GPS с телефона). Другие форматы картинками не считаются |
| `files.max_size` | `null` | Наибольший размер файла в КБ. `null` — сколько разрешает PHP (`upload_max_filesize`, `post_max_size`) |
| `files.max_pixels` | `32_000_000` | Картинки больше стольких пикселей отклоняются, не открываясь: такая картинка заняла бы гигабайты памяти. Отклоняется и картинка, которой не хватит `memory_limit` PHP |
| `files.hidden` | `[]` | Имена папок и файлов (можно с `*`: `thumbs`, `*.bak`), которые менеджер не показывает, не открывает и не удаляет по отдельности; всё внутри скрытой папки тоже скрыто. По прямым адресам они доступны. Подробно — [files.md](files.md#скрытые-папки-и-файлы) |
| `images.max_width` | `2560` | Картинка шире уменьшается при загрузке. `null` — не уменьшать |
| `images.quality` | `90` | Качество JPEG и WebP при сохранении, от 1 до 100 |
| `images.presets` | `[]` | Размеры WebP-вариантов для сайта: `'card' => ['widths' => [400, 800], 'ratio' => '4:3', 'watermark' => false]`. Подробно — [files.md](files.md#картинки-для-сайта-варианты-и-x-fastleopicture) |
| `images.variant_quality` | `80` | Качество WebP вариантов, от 1 до 100 |
| `images.watermark.path` | `null` | Картинка водяного знака в файловом менеджере (`uploads/watermark.png`). `null` — без знака |
| `images.watermark.position` | `'bottom-right'` | Угол знака: `top-left`, `top-right`, `bottom-left`, `bottom-right`, `center` |
| `images.watermark.opacity` | `50` | Непрозрачность знака, % |
| `images.watermark.size` | `20` | Ширина знака, % от ширины варианта |

## Языки содержимого

```php
'locales' => ['ru', 'en'],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `locales` | `null` — один язык сайта (`app.locale`) | Языки [многоязычных полей](translations.md) (`->translatable()`): вкладка на язык у поля. Первый — основной: обязателен при `required()`, его показывает список, по нему сортировка. Код языка — `ru`, `en`, `pt_BR`; другие строки пропускаются |

Язык интерфейса админки от этой настройки не зависит — он всегда русский.

## Часовой пояс

```php
'timezone' => 'Europe/Moscow',
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `timezone` | `null` | Пояс, в котором поля `DateTime` показываются и вводятся. `null` или пояс не из списка `timezone_identifiers_list()` (в том числе устаревшие имена вроде `Europe/Kiev`) — пояс сайта `app.timezone`. В базе значения хранятся в поясе сайта |

## Тексты интерфейса

Все тексты лежат в переводах пакета (`fastleo::auth`, `fastleo::layout`, `fastleo::dashboard`, `fastleo::resources`, `fastleo::actions`, `fastleo::imports`, `fastleo::files`, `fastleo::console`, `fastleo::exceptions`). Любую фразу можно заменить файлом сайта:

```php
// lang/vendor/fastleo/ru/auth.php
return [
    'heading' => 'Вход для сотрудников',
];
```

Тексты ошибок проверки форм — из `lang/ru/validation.php` сайта, а для правил, которых там нет, — из пакета ([validation.md](validation.md)).

## MCP-сервер

```php
'mcp' => [
    'allow_delete' => false,
],
```

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `mcp.allow_delete` | `false` | Разрешить MCP-серверу удалять записи — то же, что ключ `--allow-delete` у `fastleo:mcp` ([подробнее](claude.md#mcp-сервер)) |

## Иконки

В пакете весь набор [Lucide](https://lucide.dev/icons). В шаблонах:

```blade
<x-fastleo::icon name="stethoscope" class="size-5" />
```

Имя — как на сайте Lucide: латиница в нижнем регистре, цифры и дефис. Для неизвестного имени — исключение со ссылкой на список иконок.
