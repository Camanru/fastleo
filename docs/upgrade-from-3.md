# Переход с fastleo 2.x и 3.0

Fastleo 4.0 написан заново. Обратной совместимости со старыми версиями нет: другое имя пакета, другой namespace, раздел админки описывается не свойствами модели, а отдельным классом-ресурсом. Данные сайта (таблицы, загруженные файлы, пароли администраторов) остаются, но код админки придётся описать заново — по шагам ниже.

Версии 2.x и 3.0 устроены почти одинаково (свойство `$fastleo` у модели, массив `$fastleo_columns`, колонка `users.fastleo_admin`, файлы в `storage/app/public/uploads`), поэтому инструкция одна. Где они различаются, это отмечено. Отметка «3.0» значит, что такой возможности в 2.x не было, а «в части сборок 3.0» — что она была только в некоторых сборках 3.0.

Перед началом сделайте копию базы данных и папки `storage/app/public/uploads`. Переход лучше сначала пройти на локальной копии сайта.

## Что чем заменяется

### Раздел (свойства модели)

| Было | Стало в 4.0 |
|---|---|
| `public $fastleo = 'Статьи'` у модели | класс `app/Fastleo/ArticleResource.php` (наследник `Fastleo\Resource`) с `protected static string $model = Article::class` и `protected static ?string $label = 'Статьи'`. Заготовка — `php artisan fastleo:make-resource Article --generate` ([ресурсы](resources.md)) |
| `public $fastleo = false` (модель только для вложенных записей) | ресурс не нужен: вложенные записи описываются полем [HasMany](fields/has-many.md) в ресурсе родителя. Отдельного адреса у такой модели больше нет |
| модели в `app/` (2.x) или в `config('fastleo.models')` (3.0) | ресурсы в `app/Fastleo` (`fastleo.resources.path`); модель указывается в `$model` и может лежать где угодно |
| `$fastleo_group` | `protected static ?string $group` |
| `$fastleo_description` (карточка на главной) | `protected static ?string $description` — пояснение под заголовком списка |
| `$fastleo_icon` (3.0, Font Awesome: `'fa-user-md'`) | `protected static string $icon` — имя иконки [Lucide](https://lucide.dev/icons): `'stethoscope'` |
| `$fastleo_order` (3.0): `['id', 'desc']` | `protected static ?array $defaultOrder = ['id', 'desc']` (по умолчанию — новые сверху) |
| `$fastleo_position` (в части сборок 3.0) | `protected static int $position` |
| `$fastleo_per_page` (в части сборок 3.0) | `protected static ?int $perPage` |
| `$fastleo_tree = 'parent_id'` (в части сборок 3.0) | `protected static ?string $treeColumn = 'parent_id'` и метод `parent()` в модели ([дерево](resources.md#дерево)) |
| `fastleoBadge()` и `$fastleo_badge_title` (3.0) | `public static function badge(): ?int` в ресурсе — число у пункта меню. Подписи-предупреждения на главной нет, для неё — [виджет](dashboard.md) `Stat` |
| `fastleoUrl()` (в части сборок 3.0) | `public function url(Model $record): ?string` в ресурсе — кнопка «Открыть на сайте» |
| запись через query builder: `$fillable` не нужен, события Eloquent не срабатывают | запись через `fill()->save()`: **все поля формы должны быть в `$fillable` модели**, работают касты, мутаторы и observers ([запись в модель](resources.md#запись-в-модель)) |

### Поля (`$fastleo_columns`)

Раньше в форму попадали все колонки таблицы, а `$fastleo_columns` только уточнял их. В 4.0 в форме и в базе участвуют **только** поля из метода `fields()` ресурса — колонка без поля в админке не видна и не меняется.

| Было | Стало в 4.0 |
|---|---|
| ключ массива — имя колонки | первый аргумент `make()`: `Text::make('title')` |
| `'title' => 'Заголовок'` | второй аргумент `make()`: `Text::make('title', 'Заголовок')` или `->label('Заголовок')` |
| `'description' => '…'` | `->help('…')` |
| `'placeholder' => '…'` | `->placeholder('…')` |
| `'visible' => false` | `->hideInList()` |
| `'visible' => true` у длинного текста | `Textarea::make(...)->showInList()` |
| `'type' => 'string'` (или тип по колонке `varchar`) | [Text](fields/text.md) |
| `'type' => 'string'`, `'media' => true` | [Image](fields/image.md) для картинки, [File](fields/file.md) для документа; папка загрузки — `->directory('articles')` |
| `'type' => 'integer'` / `'number'` | [Number](fields/number.md): `Number::make('price')->integer()`; дробное — без `integer()` |
| `'type' => 'checkbox'` / `'boolean'` | [Toggle](fields/toggle.md) (или [Checkbox](fields/checkbox.md)) и каст `'boolean'` в модели |
| `'type' => 'text'` | [Textarea](fields/textarea.md) |
| `'type' => 'text'`, `'tinymce' => true` | [RichText](fields/rich-text.md), обычно с `->hideInList()` |
| `'type' => 'select'`, `'data' => ['s' => 'Маленький', …]` | [Select](fields/select.md): `Select::make('size')->options(['s' => 'Маленький', …])` |
| `'type' => 'select'`, `'data' => 'App\Models\Page:id:title'` у колонки `page_id` | [BelongsTo](fields/belongs-to.md): `BelongsTo::make('page_id', 'Страница')` и метод `page()` в модели; подпись — `->titleColumn('title')`, условие `:menu:1` — `->query(fn ($query) => $query->where('menu', 1))` |
| `'type' => 'select'`, `'data' => 'Модель:ключ:подпись'` у колонки не-связи | `Select::make(...)->options(fn () => Модель::query()->orderBy('title')->pluck('title', 'id')->all())` |
| `'type' => 'select'`, `'multiple' => true` (значения строкой `3,7,12`) | связь с другой таблицей — [BelongsToMany](fields/belongs-to-many.md) с промежуточной таблицей; фиксированный список — `Select::make(...)->multiple()` с JSON-колонкой и кастом `'array'`. Старые значения нужно перенести ([ниже](#несколько-значений-через-запятую)) |
| `'type' => 'include'`, `'model'`, `'relation'` | [HasMany](fields/has-many.md): `HasMany::make('rows', 'Блоки')->fields([...])` — имя поля = метод связи |
| колонки `date`, `time`, `datetime` (3.0 — поля даты по типу колонки) | [Date](fields/date.md), [Time](fields/time.md), [DateTime](fields/date-time.md) |
| HTML-типы во вложенных записях (3.0): `email`, `url`, `tel`, `color` | [Email](fields/email.md), [Url](fields/url.md), [Tel](fields/tel.md), [Color](fields/color.md) |
| `created_at` с `title` — колонка в списке | `DateTime::make('created_at', 'Добавлен')->onlyInList()->sortable()` |
| поиск по всем строковым колонкам | `->searchable()` у нужных полей |

Все типы полей — [в таблице полей](fields/README.md#типы).

### Служебные колонки

| Было | Стало в 4.0 |
|---|---|
| колонка `sort` — стрелки «выше/ниже», «Пересчитать порядок» | `protected static ?string $sortColumn = 'sort'` в ресурсе: стрелки и перетаскивание, после перемещения номера пересчитываются сами ([ручной порядок](resources.md#ручной-порядок)) |
| колонка `menu` — «глаз» в списке, «Показать все / Скрыть все» | обычное поле `Toggle::make('menu', 'Показывать на сайте')` (в `$fillable`, каст `'boolean'`), при желании — фильтр `BooleanFilter::make('menu', 'На сайте')` ([фильтры](list.md#фильтры)) и своё [массовое действие](actions.md) |
| `password`, `remember_token` скрыты списком `exclude` | скрыто всё, чего нет в `fields()`; пароль — поле [Password](fields/password.md) |
| `exclude.get_list`, `exclude.list_name`, `exclude.row_name` в конфиге | не нужны |

### Вход и администраторы

| Было | Стало в 4.0 |
|---|---|
| колонка `users.fastleo_admin` (`boolean`, может быть `NULL`), вход при `fastleo_admin = 1` | та же по смыслу колонка `fastleo_admin` (`boolean`, по умолчанию `false`, с индексом) — её создаёт миграция пакета, старую колонку нужно переименовать до неё ([шаг 5](#5-миграции-и-перенос-администраторов)) |
| вход по сессии fastleo (2.x) или middleware `fastleo.auth` (3.0) | guard `fastleo` и метод `canAccessFastleo()` модели пользователя: интерфейс `Fastleo\Auth\FastleoUser` и трейт `Fastleo\Auth\HasFastleoAccess` ([установка](installation.md#2-модель-пользователя)) |
| `php artisan fastleo:user --admin` | `php artisan fastleo:user admin@site.ru` |
| раздел «Пользователи» (`/fastleo/users`) — все пользователи сайта | раздел «Администраторы» (`/fastleo/users`) — только пользователи с `fastleo_admin = true` ([подробнее](auth.md#администраторы)) |
| `remember_days` (в части сборок 3.0) | `auth.remember_days` в `config/fastleo.php` |

### Конфиг `config/fastleo.php`

Старый файл не подходит: ключи другие, а ключ `images` значит другое. Его нужно удалить до установки 4.0 ([шаг 2](#2-удалить-старый-пакет)).

| Было | Стало в 4.0 |
|---|---|
| `uploads` = `'public/uploads'` (папка на диске `local`) | `files.disk` = `'public'` и `files.root` = `'uploads'` — та же папка `storage/app/public/uploads` |
| `images` — расширения картинок | `files.images` |
| `extensions` (3.0) | `files.extensions` |
| `timezone` (3.0) | `timezone` — пояс полей `DateTime` |
| `models.namespace`, `models.path` (3.0) | `resources.namespace`, `resources.path` — папка ресурсов, а не моделей |
| `per_page` (в части сборок 3.0) | `list.per_page` (по умолчанию 25) |
| `editor` — настройки TinyMCE (в части сборок 3.0) | нет; высота редактора — `RichText::make(...)->height(500)`, разрешённые теги — `->sanitizeUsing(...)` |
| `menu_links` (3.0) | `menu.links` — ссылки по адресу (`label`, `url`, `icon`, `group`) или своя страница `Fastleo\Page` ([свои страницы](pages.md)) |
| `info_report`, `info_panels`, `info_actions` (3.0) | свой дашборд — класс `App\Fastleo\Dashboard` с виджетами ([дашборд](dashboard.md)); кнопка «Очистить кеш» есть в виджете `ServerStatus` |

Все ключи — [в настройках](configuration.md).

### Файлы и настройки

| Было | Стало в 4.0 |
|---|---|
| файловый менеджер `/fastleo/filemanager`, файлы в `storage/app/public/uploads` | менеджер `/fastleo/files` ([файлы](files.md)), та же папка — файлы переносить не нужно |
| в колонке хранится адрес `/storage/uploads/doctors/ivanov.jpg` | хранится путь относительно диска `uploads/doctors/ivanov.jpg`; старые значения нужно перевести ([шаг 6](#6-файлы)) |
| миниатюры в папках `thumbs` внутри каждой папки загрузок | миниатюры вне корня загрузок, в `storage/app/public/fastleo/thumbs`; старые папки `thumbs` можно удалить |
| галочка «Уменьшить до 1024 px» при загрузке | картинка шире `images.max_width` (2560) уменьшается сама |
| водяной знак из «Настроек» (картинка или текст), ставится на оригинал при загрузке | `images.watermark` в конфиге: только картинка и только на WebP-варианты для сайта, оригинал остаётся чистым ([водяной знак](files.md#водяной-знак)) |
| таблица `fastleo_settings` (`key`, `value`), модель `Fastleo\Fastleo\app\FastleoSetting` | своя страница настроек — класс `Fastleo\Settings` с полями, чтение — `fastleo_setting('contacts.phone')` ([настройки сайта](settings.md)). Таблица называется так же, но устроена иначе |

### Код сайта, команды и адреса

| Было | Стало в 4.0 |
|---|---|
| пакет `fastleo/fastleo`, namespace `Fastleo\Fastleo\…` | пакет `camanru/fastleo`, namespace `Fastleo\…` |
| событие `Fastleo\Fastleo\app\Events\RecordSaved` (3.0) | observer модели (`saved`, `deleted`) — запись идёт через Eloquent |
| свои маршруты с middleware `fastleo.auth` (3.0) | `Fastleo::routes(function () { … })` — вход, CSRF и заголовки админки ([кнопки и формы](pages.md#кнопки-и-формы-на-своей-странице)) |
| шаблоны в `resources/views/vendor/fastleo` | шаблоны другие, старые замены не подойдут; оформление — `css` в конфиге ([оформление](configuration.md#оформление)), свои поля — [custom.md](fields/custom.md) |
| `vendor:publish --tag=fastleo` (2.x), `fastleo:resource` (2.x и 3.0) — CSS/JS в `storage/app/public/fastleo` | `fastleo:install`, CSS/JS в `public/vendor/fastleo`, обновляются сами при `composer update` |
| `fastleo:clear` — `optimize:clear` | `php artisan optimize:clear`; в 4.0 `fastleo:clear` сбрасывает только кеш списка разделов |
| `/fastleo` — форма входа, `/fastleo/info` — главная | `/fastleo/login` — вход, `/fastleo` — дашборд |
| `/fastleo/app/Article` | `/fastleo/articles` (`$slug` ресурса) |
| `/fastleo/setting` | `/fastleo/settings/{страница}` |
| экспорт всех колонок, импорт всегда включён | экспорт и импорт только полей ресурса, импорт включается `$importable = true` ([экспорт и импорт](import-export.md)) |

## Переход по шагам

### 1. Требования

4.0 работает на PHP 8.4+ и Laravel 13 ([требования](installation.md#требования)).

- **Сайт на 3.0** уже на Laravel 13 — достаточно PHP 8.4.
- **Сайт на 2.x** работает на Laravel 5–7. Fastleo 2.x не ставится на новые версии Laravel, поэтому сначала удалите его (шаг 2), затем обновите сайт до Laravel 13 и PHP 8.4 по официальным руководствам обновления Laravel — это отдельная работа, и админки в это время на сайте нет. Потом продолжайте с шага 3.

### 2. Удалить старый пакет

Запишите, кто из пользователей администратор, и значение водяного знака — пригодятся, если что-то пойдёт не так:

```sql
SELECT id, email FROM users WHERE fastleo_admin = true;
SELECT * FROM fastleo_settings;
```

Удалите пакет:

```bash
composer remove fastleo/fastleo
```

Если пакет был подключён локальной копией, уберите из `composer.json` сайта блок `repositories` с путём к ней и саму папку (например, `packages/fastleo`). Если провайдер `Fastleo\Fastleo\ServiceProvider` вписан вручную в `config/app.php` или `bootstrap/providers.php` — уберите строку.

Удалите то, что старая версия положила в сайт:

| Что | Почему |
|---|---|
| `config/fastleo.php` | старые ключи не подходят, а `fastleo:install` существующий файл не перезаписывает. Нужные значения перенесите в новый файл по [таблице выше](#конфиг-configfastleophp) |
| `storage/app/public/fastleo` | старые CSS/JS (в том числе устаревший TinyMCE) открыты из интернета, а 4.0 хранит в этой папке миниатюры и варианты картинок |
| `resources/views/vendor/fastleo` | замены старых шаблонов |

Пакет мог тянуть за собой `intervention/image`, `maatwebsite/excel` и другие библиотеки. Если код сайта пользуется ими сам, добавьте их в `composer.json` сайта явно.

Найдите в коде сайта всё, что связано со старой версией:

```bash
grep -rnE 'Fastleo\\Fastleo|\$fastleo|fastleoBadge|fastleoUrl|fastleo\.auth|FastleoSetting|RecordSaved' app bootstrap config routes resources
```

Свойства `$fastleo*` у моделей пока можно оставить как подсказку для описания разделов (шаг 7) — 4.0 их не читает. Удалите их, когда раздел будет готов.

### 3. Установить 4.0

```bash
composer require camanru/fastleo
php artisan fastleo:install
```

`fastleo:install` публикует новый `config/fastleo.php` и CSS/JS в `public/vendor/fastleo`, кладёт skill для Claude и проверяет модель пользователя. Код выхода `1` — модель пользователя ещё не подключена (шаг 4). Подробно — [установка](installation.md).

### 4. Модель пользователя

```php
namespace App\Models;

use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\HasFastleoAccess;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FastleoUser
{
    use HasFastleoAccess;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'fastleo_admin' => 'boolean',
        ];
    }
}
```

`fastleo_admin` не должен быть в `$fillable`, а у модели пользователя не должно быть `$guarded = []` — иначе форма регистрации или профиля сайта сможет выдать права администратора. На сайтах 2.x такое встречается — проверьте.

Если у модели осталось свойство `$fastleo` — удалите.

### 5. Миграции и перенос администраторов

Старая версия уже создала колонку `users.fastleo_admin` и таблицу `fastleo_settings`. Миграции 4.0 создают их заново, со своей схемой, и на старой базе упадут с ошибкой «колонка (таблица) уже существует». Поэтому до миграций пакета старые переименовываются, а после — флаги администраторов переносятся в новую колонку.

Laravel выполняет новые миграции сайта и пакета вместе, по порядку имён файлов. Первая миграция ниже должна идти **раньше** миграций пакета (их имена начинаются с `2026_10_28`), вторая — **позже**. Поэтому возьмите имена файлов как в примере.

```php
// database/migrations/2026_10_27_000000_prepare_fastleo_4.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Убирает с дороги колонку и таблицу старой версии fastleo.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'fastleo_admin')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->renameColumn('fastleo_admin', 'fastleo_admin_old');
            });
        }

        if (Schema::hasTable('fastleo_settings')) {
            Schema::rename('fastleo_settings', 'fastleo_settings_old');
        }
    }
};
```

```php
// database/migrations/2026_10_31_000000_finish_fastleo_4.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Переносит администраторов в новую колонку и удаляет остатки старой версии.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'fastleo_admin_old')) {
            DB::table('users')->where('fastleo_admin_old', true)->update(['fastleo_admin' => true]);

            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('fastleo_admin_old');
            });
        }

        Schema::dropIfExists('fastleo_settings_old');

        // Записи о миграциях старого пакета: их файлов больше нет
        DB::table(config('database.migrations.table', 'migrations'))
            ->whereIn('migration', [
                '2018_05_01_000000_fastleo_admin_column_users',
                '2019_12_15_000000_create_fastleo_settings_table',
            ])
            ->delete();
    }
};
```

```bash
php artisan migrate
```

Проверки `hasColumn` и `hasTable` делают миграции безопасными для новой пустой базы (тесты, новый сервер): там им нечего переносить. Если пользователи лежат не в таблице `users`, поправьте имя таблицы.

Администраторы входят со своими прежними паролями. Вход в 4.0 сравнивает email в нижнем регистре: если у администратора в адресе есть заглавные буквы (`Admin@Site.ru`), выполните для него

```bash
php artisan fastleo:user admin@site.ru --no-interaction
```

Команда исправит регистр email и выдаст доступ, не меняя пароль. Так же можно выдать доступ администраторам по списку из шага 2 вместо переноса флагов миграцией.

### 6. Файлы

Сами файлы остаются на месте: старая папка `storage/app/public/uploads` — это корень файлового менеджера 4.0 по умолчанию (диск `public`, папка `uploads`). Если в старом конфиге `uploads` был другим, например `'public/files'`, поменяйте в `config/fastleo.php` ключ `files.root` на `'files'`. Ссылка `public/storage` нужна, как и раньше: `php artisan storage:link`.

**Значения в колонках.** Старая версия писала в колонку адрес `/storage/uploads/…`, 4.0 — путь на диске `uploads/…`. Поля `Image` и `File` старый адрес не принимают: не покажут превью и не дадут сохранить запись. Переведите все колонки, где было `'media' => true` (и во вложенных таблицах `include`), — `/storage/` в начале отрезается:

```php
// database/migrations/2026_10_31_000100_convert_fastleo_file_paths.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Таблицы и колонки с файлами: бывшие поля с 'media' => true.
     *
     * @var array<string, list<string>>
     */
    private array $columns = [
        'articles' => ['image'],
        'doctors' => ['photo', 'price_file'],
    ];

    /**
     * '/storage/uploads/a.jpg' → 'uploads/a.jpg'.
     */
    public function up(): void
    {
        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                $wrapped = DB::getQueryGrammar()->wrap($column);

                DB::table($table)
                    ->where($column, 'like', '/storage/%')
                    ->update([$column => DB::raw("substr({$wrapped}, 10)")]);
            }
        }
    }
};
```

После миграции проверьте, что не осталось значений в другом виде — адрес с доменом (`https://site.ru/storage/…`) или файл вне папки загрузок (`/images/logo.png`), который вписали вручную:

```sql
SELECT id, image FROM articles WHERE image IS NOT NULL AND image NOT LIKE 'uploads/%';
```

Такие файлы перенесите в менеджер и выберите в записи заново.

**Шаблоны сайта.** Раз в колонке теперь путь, а не адрес, вывод картинки меняется:

```blade
{{-- было --}}
<img src="{{ $article->image }}" alt="">

{{-- стало --}}
<img src="{{ Storage::disk('public')->url($article->image) }}" alt="">

{{-- или с WebP-вариантами (размер card описан в images.presets) --}}
<x-fastleo::picture :src="$article->image" preset="card" />
```

Картинки и ссылки внутри текстов визуального редактора менять не нужно: [RichText](fields/rich-text.md) вставляет адреса тем же видом `/storage/uploads/…`.

**Старые миниатюры.** Папки `thumbs` внутри папок загрузок 4.0 не использует и показывает в менеджере как обычные папки. Если шаблоны сайта не ссылаются на `/thumbs/` (проверьте `grep -rn "thumbs" resources`), удалите их — в менеджере или командой:

```bash
find storage/app/public/uploads -type d -name thumbs -prune -exec rm -rf {} +
```

**Водяной знак.** Старый знак уже стоит на загруженных оригиналах, с ними ничего делать не нужно. Новый знак задаётся в конфиге и ставится только на варианты для сайта ([водяной знак](files.md#водяной-знак)).

### 7. Разделы

Для каждой модели, у которой было `$fastleo`:

1. **Модель.** Добавьте в `$fillable` все колонки, которые правятся в админке (раньше это не требовалось), и касты: `'boolean'` для галочек, `'array'` для JSON-полей. Удалите свойства `$fastleo*` и методы `fastleoBadge()`, `fastleoUrl()`, когда перенесёте их в ресурс.
2. **Ресурс.** Создайте заготовку по таблице:

   ```bash
   php artisan fastleo:make-resource Article --generate
   php artisan fastleo:make-resource Article --generate --model="App\Article"   # модель 2.x в app/
   ```

   `--generate` сам подберёт `Toggle` для `boolean`, `Image` для колонок `image`/`photo`, `$sortColumn` для `sort` и т. д. ([как выбирается поле](resources.md#команда-fastleomake-resource)). Колонки не из `$fillable` и непонятные JSON-колонки он пропускает с предупреждением.
3. **Доработайте поля** по старому `$fastleo_columns` и [таблице выше](#поля-fastleo_columns): подписи, `help()`, `RichText` вместо `Textarea` там, где был `tinymce`, `Select` с вариантами, `BelongsTo`, `HasMany` для `include`, `directory()` у картинок. Подписи и иконку заготовка ставит английские — переведите.
4. **Колонка `menu`.** `--generate` превратит её в `Toggle` с подписью «Menu» — назовите по-человечески («Показывать на сайте»).
5. **Колонка `sort`.** Если в ней есть пустые значения (старые записи), заполните: `UPDATE articles SET sort = id WHERE sort IS NULL`.

Разделы находятся сами, регистрировать их не нужно. На боевом сайте после добавления разделов выполните `php artisan optimize`.

#### Вложенные записи (include)

```php
// было — у модели Page
public $fastleo_columns = [
    'rows' => ['title' => 'Блоки', 'type' => 'include', 'model' => 'App\Models\Row', 'relation' => 'rows'],
];

// стало — в PageResource::fields()
HasMany::make('rows', 'Блоки')->fields([
    Text::make('title', 'Заголовок'),
    Image::make('image', 'Фото')->directory('pages'),
    Textarea::make('text', 'Текст'),
]),
```

Метод связи `rows()` в модели `Page` остаётся. У модели блока (`Row`) уберите `$fastleo = false` и `$fastleo_columns`, добавьте поля блока в `$fillable` (внешний ключ `page_id` пакет пишет сам). Ресурс для `Row` не нужен.

#### Несколько значений через запятую

`select` с `'multiple' => true` хранил ключи строкой `3,7,12`. В 4.0 связь с записями другой таблицы — промежуточная таблица и поле [BelongsToMany](fields/belongs-to-many.md). Пример переноса для врачей и услуг (колонка `doctors.services`):

```php
// database/migrations/2026_10_31_000200_move_doctor_services.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Строку «3,7,12» в doctors.services переносит в таблицу doctor_service.
     */
    public function up(): void
    {
        Schema::create('doctor_service', function (Blueprint $table): void {
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['doctor_id', 'service_id']);
        });

        DB::table('doctors')->whereNotNull('services')->orderBy('id')->each(function (object $doctor): void {
            $ids = array_filter(array_map('intval', explode(',', (string) $doctor->services)));

            DB::table('doctor_service')->insert(
                DB::table('services')->whereIn('id', $ids)->pluck('id')
                    ->map(fn ($serviceId): array => ['doctor_id' => $doctor->id, 'service_id' => $serviceId])
                    ->all(),
            );
        });

        Schema::table('doctors', function (Blueprint $table): void {
            $table->dropColumn('services');
        });
    }
};
```

В модели `Doctor` — связь `services(): BelongsToMany`, в ресурсе — `BelongsToMany::make('services', 'Услуги')`. Если варианты — фиксированный список, а не записи таблицы, используйте `Select::make(...)->multiple()`: колонка `json` с кастом `'array'`, значение — JSON-массив вместо строки через запятую.

### 8. Настройки, главная и свои страницы

- **Настройки.** Старая страница «Настройки» хранила только водяной знак. Если сайт читал из `fastleo_settings` что-то своё, опишите [страницу настроек](settings.md) (`Fastleo\Settings`) и замените чтение на `fastleo_setting('страница.поле')`. Значения внесите в админке заново.
- **Главная** (`info_report`, `info_panels`, `info_actions`, 3.0) — [дашборд](dashboard.md) с виджетами `Stat`, `Chart`, `Table`, `Sections`, `ServerStatus`.
- **Свои страницы в меню** (`menu_links`, 3.0) — класс `Fastleo\Page` (`php artisan fastleo:make-page Report`), а кнопки и формы на нём — маршруты в `Fastleo::routes()` ([свои страницы](pages.md)). Простая ссылка — `menu.links` в конфиге.
- **`RecordSaved`** (3.0) — перенесите слушателей в observers моделей. Учтите и обратное: раньше админка не вызывала observers и мутаторы, теперь вызывает — проверьте, что существующие observers сайта (письма, кеш) ведут себя при правке из админки как нужно.

### 9. Проверка

```bash
php artisan fastleo:doctor
php artisan fastleo:inspect
```

`fastleo:doctor` проверяет окружение (модель пользователя, миграции, ассеты, `public/storage`, очередь) и каждый раздел: колонки, `$fillable`, касты, связи. Ошибок быть не должно — исправьте по подсказкам и повторите ([все проверки](claude.md#fastleodoctor)). `fastleo:inspect` показывает разделы, адреса и поля — удобно сверить со старой админкой.

Затем войдите в `/fastleo/login`, откройте каждый раздел, сохраните по записи (особенно с картинками и текстами в редакторе) и посмотрите страницы сайта, где выводятся картинки.

На боевом сайте: те же шаги (`composer install`, `php artisan migrate`, удаление `storage/app/public/fastleo`, при необходимости `php artisan storage:link`), затем `php artisan optimize`.

## Пример: статья

Таблица: `id`, `sort`, `title`, `image`, `text`, `is_published`, `created_at`, `updated_at`.

**Было** (3.0; в 2.x — то же без `$fastleo_icon`, модель в `app/Article.php`):

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    public $fastleo = 'Статьи';

    public $fastleo_icon = 'fa-newspaper';

    public $fastleo_group = 'Контент';

    public $fastleo_description = 'Новости и статьи блога';

    public $fastleo_columns = [
        'title' => ['title' => 'Заголовок'],
        'image' => ['title' => 'Картинка', 'media' => true, 'description' => 'Не меньше 1200 px по ширине'],
        'text' => ['title' => 'Текст', 'type' => 'text', 'tinymce' => true],
        'is_published' => ['title' => 'Опубликовано', 'type' => 'checkbox'],
    ];
}
```

**Стало** — модель без настроек админки:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $fillable = ['title', 'image', 'text', 'is_published'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }
}
```

и ресурс:

```php
// app/Fastleo/ArticleResource.php (адрес раздела — /fastleo/articles)
namespace App\Fastleo;

use App\Models\Article;
use Fastleo\Fields\Image;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;

final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $label = 'Статьи';

    protected static ?string $singular = 'Статья';

    protected static ?string $description = 'Новости и статьи блога';

    protected static string $icon = 'newspaper';

    protected static ?string $group = 'Контент';

    protected static ?string $sortColumn = 'sort';

    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->searchable()->sortable(),
            Image::make('image', 'Картинка')->directory('articles')->help('Не меньше 1200 px по ширине'),
            RichText::make('text', 'Текст')->hideInList(),
            Toggle::make('is_published', 'Опубликовано'),
        ];
    }
}
```

Колонку `sort` пакет пишет сам — в `$fillable` и в `fields()` её нет. Значения `image` переводятся из `/storage/uploads/…` в `uploads/…` ([шаг 6](#6-файлы)), а в шаблоне статьи картинка выводится через `Storage::disk('public')->url($article->image)`.

## Что устроено иначе или не переносится

- **Колонки не появляются сами.** Раньше новая колонка таблицы сразу была в форме; теперь — только если добавить поле в `fields()`.
- **`$fillable` обязателен**, а запись идёт через Eloquent: срабатывают касты, мутаторы и observers модели. Поле не из `$fillable` — понятная ошибка при сохранении и в `fastleo:doctor`.
- **HTML в визуальном редакторе чистится при сохранении**: стили, цвета, шрифты, `div`, `span`, скрипты убираются ([какие теги остаются](fields/rich-text.md)). Старые тексты на сайте не меняются, пока их не сохранят в админке. Если в тексте нужна HTML-вставка без чистки (виджет, форма), используйте поле [Code](fields/code.md).
- **Водяной знак** — только картинкой и только на варианты для сайта; текстового знака и знака на оригинале нет.
- **Иконки** — Lucide вместо Font Awesome: имена нужно подобрать заново.
- **Строка `Модель:ключ:подпись`** в `data` не поддерживается — связь `BelongsTo` или замыкание в `options()`.
- **Множественный выбор строкой через запятую** не поддерживается — `BelongsToMany` или JSON-массив.
- **Переопределение шаблонов** через `resources/views/vendor/fastleo` старых шаблонов не повторить — оформление меняется через `css` в конфиге, ячейки списка — `listView()`, свои поля — [custom.md](fields/custom.md).
- **Раздел «Администраторы»** показывает только администраторов, а не всех пользователей сайта.
- **По 25 записей на странице** вместо 15 (`list.per_page` или `$perPage`).
- **Модель без раздела по прямому адресу** (`$fastleo = false`, `/fastleo/app/Row`) больше не открывается.
- **Новое, чего не было:** права через Policies моделей, корзина для `SoftDeletes`, фильтры и действия, журнал изменений с откатом, 2FA и история входов, восстановление пароля, поиск по Ctrl+K, уведомления и боты Telegram и Max для администраторов, публикации в каналы, SEO-поля и `sitemap.xml`, MCP-сервер для Claude. Обзор — в [README](../README.md).
