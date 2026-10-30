# Изменения

Формат — [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/), версии — [семантические](https://semver.org/lang/ru/).

## [4.1.1] — 2026-10-30

Правки по опыту обновления первого сайта до 4.1.0.

### Исправлено

- **`isAvailableInMcp()` у ресурса снова статический, как в 4.0.0.** В 4.1.0 он стал методом объекта, и вызов `DoctorResource::isAvailableInMcp()` падал. Теперь работают оба вызова: `DoctorResource::isAvailableInMcp()` и `$resource->isAvailableInMcp()`.
- **`fastleo:doctor` больше не пишет «Кеш списка разделов не совпадает» при включённом закрытии сайта** (`closing.enabled`): страница «Закрытие сайта», как и страницы SoftNetica, в кеш разделов не попадает.
- **Подсказка `fastleo:doctor` про потерю разметки RichText у поля со своим `sanitize()`** называет этот метод, а не `fastleo.rich_text`.
- `SetToggle` у одной записи (страница записи, меню строки списка) показывает только кнопку, которая меняет значение; в полосе выбранных записей видны обе.

### Добавлено

- `Action::visibleFor(Model $record)`: спрятать кнопку действия у одной записи, когда оно ей не нужно.
- `Stat::url()` принимает замыкание: ссылка выбирается при показе дашборда.
- В `docs/import-export.md`: импорт очищает HTML поля `RichText` так же, как форма (проверено тестом).

## [4.1.0] — 2026-10-30

Правки и доработки по опыту первого перехода сайта с 3.0.

### Изменено

- **MCP-сервер не видит разделы с данными людей.** Раздел с полем `Email` или `Tel` или полем со словом `email`, `phone`, `tel` в имени (`client_phone`, `phone2`, `phoneNumber`; в том числе внутри `HasMany`, `Repeater`, `Blocks`) закрыт, пока в ресурсе нет `protected static bool $mcpPersonalData = true;`. «Администраторы» закрыты всегда (откроет наследник `UserResource` с `$mcp = true` и `$mcpPersonalData = true`). `$mcp` остаётся `bool`, как в 4.0.0; `isAvailableInMcp()` стал методом объекта (раньше статическим); `fastleo:inspect` показывает, виден ли раздел агенту и почему закрыт.
- **Форма создания берёт значения по умолчанию из модели и базы:** `default()` поля, затем `$attributes` модели, затем DEFAULT колонки. `Toggle` без `default()` при колонке `default(true)` в новой записи включён.
- **График `Chart::countByDay` считает дни в часовом поясе админки** (`fastleo.timezone`).
- **Виджет «Проверки сайта» в админке показывает тексты как есть**; адреса, почта и пути скрываются только в отчёте для SoftNetica.
- **`php artisan optimize` и `optimize:clear` сбрасывают кеш настроек сайта.**
- Наследник готового поля (`SiteRichText extends RichText`) берёт шаблон ближайшего родителя без переопределения `view()` и `type()`.

### Добавлено

- **Быстрый переключатель `Toggle::make('menu')->quickToggle()`:** значок в списке (таблица и карточки на телефоне) становится кнопкой, клик меняет галочку без открытия формы и без перезагрузки. Проверки как у формы: Policy `update`, поле не `readonly()` и не скрыто, правила поля; запись через Eloquent (observers, журнал изменений). Маршрут `fastleo.resources.toggle`; `fastleo:doctor` предупреждает про `quickToggle()` у поля `readonly()`; `fastleo:inspect` показывает `quick_toggle`.
- **Действие `Fastleo\Actions\SetToggle`:** готовые «Показать» / «Скрыть» для выбранных записей (`SetToggle::make('menu', 'Показать на сайте', true)->icon('eye')`); `fastleo:doctor` ловит поле, которого нет в ресурсе или которое не галочка.
- **`files.hidden` в `config/fastleo.php`:** имена папок и файлов (можно с `*`), которых файловый менеджер не показывает, не открывает и не удаляет по отдельности (как и всё внутри скрытой папки) — например, старые `thumbs` от fastleo 3.
- **`rich_text` в `config/fastleo.php`:** дополнительные теги, атрибуты (`class`, `role`, `data-lpu`…), адреса плееров для `iframe` и `style` целиком (без `url()`, `expression`, `position: fixed`). `script`, `on*`, `srcdoc`, `javascript:` разрешить нельзя.
- **`fastleo:doctor` проверяет настройку `rich_text` и предупреждает, у скольких записей RichText (первые 500 в разделе) при сохранении пропадут теги, атрибуты и плееры.**
- В документе о переходе: «это не обновление, а другая админка», адрес `pages` занят (`$slug`), SEO-колонки, порядок действий с RichText.

## [4.0.0] — 2026-10-30

Пакет написан заново. Совместимости с 2.x и 3.0 нет: как перейти — [docs/upgrade-from-3.md](docs/upgrade-from-3.md).

Composer-имя — `camanru/fastleo`, namespace — `Fastleo\`.

### Требования

- PHP 8.4+, Laravel 13.
- Сайту не нужен npm: CSS и JS админки собраны в пакете.
- Очередь не обязательна: без неё задачи (WebP-варианты, импорт, уведомления) выполняются во время запроса.

### Разделы

- Раздел админки — один класс-ресурс `app/Fastleo/*Resource.php`; разделы находятся сами, список кешируется командой `fastleo:cache` (входит в `optimize`).
- В форме и при записи участвуют только поля из `fields()`; запись — через Eloquent в транзакции (касты, мутаторы, observers, `SoftDeletes`).
- Список: поиск, сортировка, постраничный вывод и «все», мобильные карточки, фильтры (Select, BelongsTo, BelongsToMany, Boolean, DateRange, свои).
- Раскладка формы: вкладки, секции, колонки, боковая панель, зависимые поля и списки.
- Корзина для `SoftDeletes`, ручной порядок и дерево с перетаскиванием, раздел только для просмотра, «открыть на сайте».
- Действия над записью и массовые с мини-формой (`Fastleo\Actions`).
- Экспорт xlsx/csv потоком, импорт с отчётом об ошибках и файлом пропущенных строк для повторной загрузки (OpenSpout).

### Поля

- 35 типов: Text, Textarea, Number, Checkbox, Toggle, Select, Radio, Range, Date, DateTime, Time, Email, Url, Tel, Color, Hidden, Password, Slug, Enum, Tags, Code, Markdown, RichText (HugeRTE, HTML чистится), Image, File, Gallery, BelongsTo, BelongsToMany, HasMany, HasOne, Repeater, KeyValue, Blocks, Seo, Secret (токены и ключи API).
- Многоязычные поля `->translatable()`, свои поля (`fastleo:make-field`).

### Файлы и картинки

- Файловый менеджер на дисках `Storage` с защитой путей и расширений.
- WebP-варианты картинок, водяной знак, компонент `<x-fastleo::picture>`; вывод блоков — `<x-fastleo::blocks>`.

### Окружение

- Дашборд с виджетами (Stat, Chart, Table, Sections, состояние сервера).
- Настройки сайта на полях и `fastleo_setting()`, свои страницы, журнал ошибок.
- Журнал изменений, история записи, откат и восстановление удалённого.
- Уведомления в шапке и боты Telegram и Max для чатов администраторов (раздел «Уведомления»), `Fastleo::notifyAdmins()`, копия на почту (`fastleo.notifications.mail`).
- Публикации в каналы Telegram и Max: разделы «Каналы» и «Публикации», кнопка `Publish` в разделах сайта, отложенная отправка, повтор без дублей, правка и удаление поста в каналах.
- Поиск по админке Ctrl+K.
- SEO-поле, `<x-fastleo::seo>`, `/sitemap.xml` (больше 50 000 адресов — индекс файлов `/sitemap-N.xml`), `/robots.txt` из пакета.
- Закрытие сайта для посетителей: страница 503, админка и администраторы работают как обычно.
- Подключение к SoftNetica (сервис обслуживания сайтов): виджет и раздел со статусом сайта. Пока токен не введён, пакет не делает запросов к SoftNetica; функцию можно отключить в конфиге.
- Раздел «Администраторы», тёмная тема, основной цвет и логотип из конфига.

### Вход и безопасность

- Guard `fastleo` поверх пользователей сайта, доступ — `canAccessFastleo()` на каждом запросе.
- Лимиты попыток входа, «Запомнить меня», выход со всех устройств при смене пароля, сброс пароля по почте, письмо о входе с нового устройства.
- Двухфакторная защита (TOTP + резервные коды), может быть обязательной.
- Права — Policies моделей на каждом действии.
- После входа админка возвращает только на свои страницы; подтверждение 2FA и новые резервные коды требуют текущий пароль; поля `Password` с любым именем не попадают ни в сессию, ни в журнал изменений.
- Форма раздела отказывает в сохранении, если PHP обрезал её по `max_input_vars`; импорт защищён от «архивной бомбы» xlsx и от прерванной задачи.
- Заголовки безопасности на всех страницах админки. Подробности — [docs/security.md](docs/security.md).

### Claude

- Skill для Claude Code (`fastleo:skill`), гайдлайны Laravel Boost, `docs/llms.txt`.
- `fastleo:inspect` — описание разделов, `fastleo:doctor` — проверка сайта и разделов.
- Генераторы `fastleo:make-resource --generate`, `make-action`, `make-filter`, `make-widget`, `make-page`, `make-field`, `make-settings`, `make-dashboard`, `make-policy`.
- MCP-сервер `fastleo:mcp` от имени администратора, с его правами.

### Команды

`fastleo:install`, `fastleo:user`, `fastleo:unlock`, `fastleo:cache`, `fastleo:clear`, `fastleo:images`, `fastleo:prune`, `fastleo:doctor`, `fastleo:inspect`, `fastleo:skill`, `fastleo:mcp`, `fastleo:softnetica-sync`, `fastleo:make-*`.

[4.1.1]: https://github.com/Camanru/fastleo/releases/tag/4.1.1
[4.1.0]: https://github.com/Camanru/fastleo/releases/tag/4.1.0
[4.0.0]: https://github.com/Camanru/fastleo/releases/tag/4.0.0
