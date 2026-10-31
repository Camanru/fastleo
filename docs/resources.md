# Разделы (ресурсы)

Раздел админки — это класс-ресурс. Он описывает модель, подписи, место в меню и поля. По нему fastleo строит пункт меню, список записей, форму создания и редактирования, страницу просмотра, удаление и корзину.

## Первый раздел

Модель и таблица — обычные, как в любом проекте на Laravel:

```bash
php artisan make:model Doctor -m                  # модель и миграция
```

```php
// database/migrations/2026_01_01_000000_create_doctors_table.php
Schema::create('doctors', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('specialty')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

```php
// app/Models/Doctor.php
namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'specialty', 'is_active'])] // или protected $fillable = [...]
class Doctor extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
```

Ресурс — в папке `app/Fastleo` (подхватывается сам, регистрировать не нужно):

```bash
php artisan fastleo:make-resource Doctor          # app/Fastleo/DoctorResource.php
```

```php
// app/Fastleo/DoctorResource.php
namespace App\Fastleo;

use App\Models\Doctor;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;

final class DoctorResource extends Resource
{
    protected static string $model = Doctor::class;

    protected static ?string $label = 'Врачи';

    protected static ?string $singular = 'Врач';

    protected static string $icon = 'stethoscope';

    public function fields(): array
    {
        return [
            Text::make('name', 'Имя')->required()->searchable()->sortable(),
            Text::make('specialty', 'Специальность')->searchable(),
            Toggle::make('is_active', 'Показывать на сайте')->default(true),
        ];
    }
}
```

Раздел открывается по адресу `/fastleo/doctors`.

**Права.** Если у модели нет Policy, раздел полностью доступен любому администратору: просмотр, создание, правка, удаление. Ограничить доступ — Policy модели, подробно в [правах доступа](authorization.md).

Поля можно сразу подобрать по колонкам таблицы — ключ `--generate` (после `php artisan migrate`):

```bash
php artisan fastleo:make-resource Doctor --generate
```

## Команда `fastleo:make-resource`

```bash
php artisan fastleo:make-resource Doctor                 # пустой ресурс для App\Models\Doctor
php artisan fastleo:make-resource Doctor --generate      # поля по колонкам таблицы doctors
php artisan fastleo:make-resource Doctor --model="App\Models\Staff\Doctor"
php artisan fastleo:make-resource Doctor --force         # перезаписать существующий файл
```

Файл появляется в папке ресурсов (`app/Fastleo/DoctorResource.php`, см. `resources.path` в [настройках](configuration.md)).

Как `--generate` выбирает поле:

| Колонка | Поле |
|---|---|
| `*_id`, если в модели есть связь `BelongsTo` с этой колонкой (`category_id` → `category()`) | `BelongsTo` |
| `slug` | `Slug::make('slug')->from('title')` (источник — `title`, `name` или первое текстовое поле) |
| `boolean`, `tinyint(1)` | `Toggle` |
| целое число | `Number::make(...)->integer()` |
| `decimal`, `float`, `double` | `Number` |
| `date` / `datetime`, `timestamp` / `time` | `Date` / `DateTime` / `Time` |
| `text` и его варианты | `Textarea`; с именем `content`, `body`, `html` — `RichText` (с `hideInList()`) |
| строка `image`, `photo`, `picture`, `avatar`, `logo`, `cover`, `thumbnail`, `poster`, `banner` (или `…_photo`) | `Image` |
| строка `file`, `document`, `attachment`, `pdf` (или `…_file`) | `File` |
| `json` `gallery`, `photos`, `images`, `pictures` с кастом `array` в модели | `Gallery` (с `hideInList()`) |
| `json` `specs`, `attributes`, `properties`, `characteristics`, `params`, `parameters` с кастом `array` | `KeyValue` |
| `json` `seo` (или `…_seo`) с кастом `array` | `Seo` |
| Переводимый атрибут модели [spatie/laravel-translatable](translations.md) | `Text` (или `RichText` для `content`, `body`, `html`) с `->translatable()` |
| строка с `email` в имени | `Email` |
| строка с `url`, `website`, `link` в имени | `Url` |
| строка с `phone` в имени или `tel` | `Tel` |
| строка с `color` в имени | `Color` |
| другая строка | `Text` |

У строковых полей (`Text`, `Email`, `Url`, `Tel`) длина колонки, отличная от обычной (255, у `Tel` — 32), переносится в `maxLength()`. В SQLite длина `varchar` не хранится.

- `NOT NULL` без значения по умолчанию → `required()` (кроме `Toggle` и `Slug`).
- Целая колонка `sort`, `position` или `sort_order` → `$sortColumn` ресурса (поля для неё нет).
- Колонка `read_at` (дата со временем, `nullable`) → `$readColumn` ресурса (поля для неё нет).
- `parent_id` со связью `parent()` на ту же модель → `$treeColumn = 'parent_id'` и поле `BelongsTo` «Parent».
- Первое текстовое поле получает `searchable()->sortable()`.
- Комментарий колонки становится подписью поля, иначе подпись — из имени колонки.
- Пропускаются: первичный ключ, `created_at`, `updated_at`, `deleted_at` (у моделей с `SoftDeletes`), `password`, `remember_token`, `fastleo_admin`, колонки 2FA (`two_factor_*`).
- Колонки, которых нет в `$fillable` модели, пропускаются с предупреждением (форма не может их записать — см. [ниже](#запись-в-модель)).
- Остальные колонки `json` (и колонки с кастом `array` в модели — в SQLite они `text`) пропускаются с предупреждением: подходящее поле (`Tags`, `Select::make()->multiple()`, `Repeater`, `KeyValue`, `Blocks`, `->translatable()`) выберите сами.
- Связи `BelongsToMany`, `HasMany`, `HasOne` по таблице не видны — добавьте их в `fields()` вручную.

Сгенерированный ресурс — отправная точка: поправьте подписи (`$label`, `$singular`, подписи полей), иконку и правила.

## Свойства

| Свойство | По умолчанию | Что задаёт |
|---|---|---|
| `$model` | обязательно | Класс модели Eloquent |
| `$label` | имя модели во множественном числе | Заголовок раздела и пункт меню: «Врачи» |
| `$singular` | имя модели | Одна запись: «Создать: Врач» |
| `$createLabel` | `null` → «Создать» | Подпись кнопки создания в списке: `'Добавить заявку'` |
| `$description` | нет | Пояснение под заголовком списка: «Опубликованные статьи видны на сайте» |
| `$icon` | `'file-text'` | Иконка в меню, имя из [Lucide](https://lucide.dev/icons) |
| `$group` | `null` | Группа меню («Контент»); `null` — пункт без группы, под «Дашбордом» |
| `$position` | `0` | Порядок в меню внутри группы: меньше — выше |
| `$slug` | имя модели во множественном числе (`doctors`, `blog-posts`) | Часть адреса: `/fastleo/{slug}` |
| `$perPage` | `null` → `fastleo.list.per_page` (25) | Записей на странице списка |
| `$perPageOptions` | `null` → `fastleo.list.per_page_options` | Варианты «На странице» под списком: `[10, 50]`; `[]` — выбора нет |
| `$defaultOrder` | `null` — новые сверху | Порядок списка: `['name', 'asc']` |
| `$readonly` | `false` | Раздел только для просмотра |
| `$sortColumn` | `null` | Колонка ручного порядка (`'sort'`): стрелки и перетаскивание в списке — [ниже](#ручной-порядок) |
| `$treeColumn` | `null` | Колонка родителя (`'parent_id'`): список деревом — [ниже](#дерево) |
| `$exportable` | `true` | Кнопка «Экспорт» в xlsx/csv — [import-export.md](import-export.md) |
| `$importable` | `false` | Кнопка «Импорт» из xlsx/csv — [import-export.md](import-export.md#импорт) |
| `$importKey` | `null` — ключ модели | Столбец сопоставления строк файла с записями (`'sku'`) |
| `$activity` | `true` | Вести [журнал изменений](activity.md) раздела: кнопка «История», откат (если журнал включён в `fastleo.activity.enabled`) |
| `$historyPanel` | `false` | Панель «История изменений» справа от формы в карточке записи (нужны `$activity` и включённый журнал `fastleo.activity.enabled`) — [related.md](related.md#боковая-колонка-карточки) |
| `$globallySearchable` | `true` | Записи раздела находит [глобальный поиск](list.md#глобальный-поиск) (Ctrl+K) |
| `$poll` | `null` | Раз в сколько секунд (5–3600) список и [связанные таблицы](related.md) раздела обновляются сами — [list.md](list.md#автообновление-списка) |
| `$readColumn` | `null` | Колонка отметки «просмотрено» (`'read_at'`): открытая запись становится просмотренной, число в меню — непросмотренные — [ниже](#отметка-просмотрено) |
| `$mcp` | `true` | Раздел доступен ИИ-агентам через [MCP-сервер](claude.md#mcp-сервер); `false` — сервер его не видит (закрывайте разделы с чужими данными и адресами получателей заявок) |

Все свойства — статические и типизированные:

```php
protected static ?string $group = 'Контент';
protected static int $position = 10;
protected static ?array $defaultOrder = ['name', 'asc'];
```

Адрес раздела (`$slug`) — латиница в нижнем регистре, цифры и дефис. Нельзя брать адреса, которые занимает сама админка: `login`, `logout`, `files`, `search`, `settings`, `pages`, `logs`, `notifications`, `profile`, `activity`, `two-factor`, `forgot-password`, `reset-password`, `sitemap`, `markdown`, `clear-cache`, `softnetica`. Два ресурса с одним адресом — ошибка.

## Методы

### `fields()`

Поля и раскладка формы. Один и тот же список задаёт форму, столбцы списка и страницу просмотра. В форме и в базе появляются **только** описанные поля: новая колонка таблицы сама в админку не попадает.

```php
public function fields(): array
{
    return [
        Text::make('name', 'Имя')->required(),
        Section::make('Контакты', [
            Tel::make('phone', 'Телефон'),
            Email::make('email', 'Email'),
        ]),
    ];
}
```

Типы полей — [fields/](fields/README.md), раскладка — [layout.md](layout.md).

### `filters()`

Фильтры над списком — [list.md](list.md#фильтры).

```php
use Fastleo\Filters\BooleanFilter;
use Fastleo\Filters\SelectFilter;

public function filters(): array
{
    return [
        SelectFilter::make('specialty'),
        BooleanFilter::make('is_active', 'Принимает'),
    ];
}
```

### `relations()`

Связанные таблицы под формой записи: машины и заявки клиента, статьи рубрики. Подробно — [related.md](related.md).

```php
public function relations(): array
{
    return [Related::make('cars', CarResource::class)->label('Машины')];
}
```

### `actions()`

Действия над записью и над выбранными — [actions.md](actions.md).

```php
public function actions(): array
{
    return [new PublishArticles];
}
```

### `badge()`

Число рядом с пунктом меню, например новые заявки. `null` или `0` — без числа.

```php
public static function badge(): ?int
{
    return Feedback::query()->where('is_new', true)->count() ?: null;
}
```

Метод вызывается на каждой странице админки — держите запрос лёгким (индекс по колонке условия). Для входящих (заявки, сообщения) обычно хватает [отметки «просмотрено»](#отметка-просмотрено): с ней число считается само.

### `inMenu()`

Показывать ли пункт меню (по умолчанию — всегда). Адрес раздела работает и без пункта, права проверяются как обычно.

```php
public static function inMenu(): bool
{
    return config()->boolean('shop.enabled');
}
```

### `title()`

Название записи: заголовок формы и страницы просмотра, хлебные крошки, подпись «Ветка: …» в дереве и строка результата поиска (Ctrl+K). По умолчанию это значение **первого столбца списка, который не является связью** (`BelongsTo`, `BelongsToMany`), текстом, без тегов; у многоязычного поля — первый язык из `fastleo.locales`. Если первой стоит связь с родителем, заголовком станет следующий столбец. Поэтому в `fields()` первым ставят поле с названием: если первым стоит `Select` или `Image`, в заголовке окажется их значение, а не название.

Если порядок полей менять не хочется, переопределите метод:

```php
use Illuminate\Database\Eloquent\Model;

public function title(Model $record): string
{
    return $record->name;
}
```

Метод возвращает обычную строку (не HTML). Варианты связи `BelongsTo` берут название из `titleColumn()`, журнал изменений — из `title`, `name` и подобных колонок: `title()` на них не влияет. Столбцы списка метод тоже не меняет.

### `url()`

Адрес записи на сайте — кнопка «Открыть на сайте» в форме и на странице просмотра. `null` — кнопки нет.

```php
use Illuminate\Database\Eloquent\Model;

public function url(Model $record): ?string
{
    return route('doctors.show', $record);
}
```

### `sitemap()`

Какие записи раздела попадают в публичную карту сайта `/sitemap.xml`. По умолчанию `null` — раздела в карте нет. Адрес записи берётся из `url()`. Подробнее — [seo.md](seo.md#карта-сайта).

```php
use Illuminate\Database\Eloquent\Builder;

public function sitemap(Builder $query): ?Builder
{
    return $query->where('is_published', true);
}
```

### `query()`

Ограничение выборки: какие записи видны в разделе. Действует на список, просмотр, изменение и удаление — запись вне выборки открыть нельзя (ответ 404).

```php
use Illuminate\Database\Eloquent\Builder;

public function query(Builder $query): Builder
{
    return $query->where('type', 'news');
}
```

Так один и тот же класс модели можно показать двумя разделами («Новости» и «Статьи»). Значение `type` при создании задайте скрытым полем: `Hidden::make('type')->default('news')`.

## Отметка «просмотрено»

Для входящих — заявок, сообщений, обращений: новая запись выделена в списке и считается в меню, пока её не открыли.

```php
// миграция
$table->timestamp('read_at')->nullable()->index();

// app/Fastleo/LeadResource.php
protected static ?string $readColumn = 'read_at';
```

- **Открыли запись** (просмотр или правка) — она просмотрена: в колонку пишется время. Отметка общая: кто-то из администраторов открыл заявку — она просмотрена для всех. Повторное открытие время не меняет.
- **Число в меню** — непросмотренные записи раздела с учётом [`query()`](#query). Свой `badge()` в ресурсе главнее.
- **В списке** непросмотренные строки выделены жирным (и в [связанных таблицах](related.md)).
- **Действия** «Просмотрено» и «Не просмотрено» — у записи и над выбранными; нужно право `view`, работают и в разделе [только для просмотра](#только-просмотр). «Не просмотрено» на странице записи возвращает в список (или на страницу, откуда запись открыли): иначе она тут же снова стала бы просмотренной.
- Отметка ставится прямым запросом: события модели (observers) не срабатывают, `updated_at` не меняется, в [журнал изменений](activity.md) не попадает. Чтение записи через MCP и выгрузка её не ставят.
- В модель колонку добавлять не нужно (ни в `$fillable`, ни в поля ресурса); каст `'read_at' => 'datetime'` пригодится, если выводите время на сайте. Если `query()` раздела выбирает колонки (`select(...)`), добавьте в них колонку отметки — иначе строки не выделяются.
- Колонка — только дата со временем, допускающая `NULL` (не boolean `is_read`): `fastleo:doctor` проверит, что она есть в таблице и подходит.
- Ключи `mark-read` и `mark-unread`, как и `delete`, `restore`, `force-delete`, заняты встроенными действиями в любом разделе — свои действия называйте иначе ([действия](actions.md)).

## Ручной порядок

```php
protected static ?string $sortColumn = 'sort';
```

```php
// Миграция
$table->unsignedInteger('sort')->default(0);
```

Список идёт по этой колонке (затем по ключу), у строк появляются стрелки «выше» / «ниже» и ручка для перетаскивания. После перемещения соседи перенумеровываются 1, 2, 3… — через Eloquent, observers модели срабатывают. Новая запись, а также запись, восстановленная из корзины, встаёт в конец. Если очистить поле порядка в форме (`Number::make('sort')`), запись тоже уйдёт в конец, а не получит `NULL`. У второго ресурса той же модели без своего `$sortColumn` порядок не расставляется: колонка получит значение по умолчанию. Колонку пишет пакет: в `$fillable` и в `fields()` её добавлять не нужно (если поле `Number::make('sort')` всё же есть в форме, введённое значение главнее).

Стрелки и ручка видны, пока список не отсортирован по столбцу, нет поиска и это не корзина. `$defaultOrder` при `$sortColumn` не действует. Право — метод `reorder` Policy или, если его нет, `update` ([authorization.md](authorization.md)). Подробнее о списке — [list.md](list.md#ручной-порядок).

## Дерево

```php
protected static ?string $treeColumn = 'parent_id';
protected static ?string $sortColumn = 'sort'; // порядок внутри ветки, необязательно

public function fields(): array
{
    return [
        Text::make('title', 'Название')->required(),
        BelongsTo::make('parent_id', 'Родитель')->hideInList(),
    ];
}
```

```php
// Миграция
$table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();

// Модель
public function parent(): BelongsTo
{
    return $this->belongsTo(self::class, 'parent_id');
}
```

Что даёт дерево:

- список показывает записи деревом с отступами; ветки сворачиваются (состояние запоминается в браузере), есть «Свернуть всё» / «Развернуть всё»;
- число у записи — сколько у неё вложенных; клик открывает только эту ветку (`?parent=ID`);
- кнопка «+» у записи создаёт вложенную — родитель подставлен в форму;
- запись с вложенными удалить нельзя: «Сначала удалите или перенесите вложенные записи»;
- в поле «Родитель» варианты идут деревом с отступами «— »; выбрать саму запись или её потомка нельзя («Нельзя перенести запись внутрь неё самой»);
- с `$sortColumn` стрелки и перетаскивание меняют порядок только среди соседей; в другую ветку запись переносится полем «Родитель» и встаёт в её конец;
- при поиске или сортировке по столбцу список становится плоским;
- запись, в «Родителе» которой стоит скрытая `query()` запись, стрелками двигается только среди таких же «сирот»; удалить родителя навсегда нельзя, пока его вложенные лежат в корзине.

Записи, чей родитель удалён или скрыт `query()`, показываются корнями; записи, замкнутые в цикл в базе, тоже выводятся — ничего не пропадает. Глубина не ограничена.

## Запись в модель

Форма записывает значения через `fill()` модели и `save()` — работают касты, мутаторы, observers и события Eloquent. Логику сайта (сбросить кеш, отправить письмо) пишите в observer модели, а не в админке.

Если observer отменяет запись (`saving`, `creating`, `updating`, `deleting` вернул `false`), админка откатывает всю транзакцию — вместе со связями и вложенными блоками — и показывает ошибку «Сайт отменил сохранение» (или удаление) вместо «Сохранено».

`$fillable` модели соблюдается. Если поле ресурса не разрешено моделью, сохранение остановится с ошибкой:

```
Поле «specialty» ресурса App\Fastleo\DoctorResource не разрешено моделью App\Models\Doctor.
Добавьте «specialty» в $fillable модели или уберите поле из ресурса.
```

Так ресурс и модель не расходятся молча (значение не «теряется»). Колонки, которые форма менять не должна (`is_admin`, счётчики), держите вне `$fillable` и не добавляйте в ресурс.

Что ещё защищает запись:

- в модель попадают только поля ресурса, видимые в форме и не помеченные `readonly()`/`disabled()`; остальные ключи запроса игнорируются;
- поле, которого нет в запросе, не меняется;
- запись и связанные действия идут в одной транзакции;
- ошибка базы, которую не поймала проверка формы (уникальность, связь, длина), показывается понятным текстом над формой, введённые значения сохраняются — см. [validation.md](validation.md).

## События модели

Всё, что админка делает с записью, идёт через модель: по одной записи, через `save()`/`delete()`/`restore()`/`forceDelete()`. Массовых запросов `query()->update()/delete()` нет, поэтому observers и события Eloquent видят каждую запись.

| Операция | События модели |
|---|---|
| Создание и правка в форме | `saving`, `creating`/`updating`, `created`/`updated`, `saved` |
| Удаление записи | `deleting`, `deleted`; с `SoftDeletes` — удаление в корзину, плюс `trashed` |
| Массовое удаление и другие массовые действия | как у одной записи — по каждой записи отдельно, в своей транзакции |
| Восстановление из корзины | `restoring`, `saving`, `updating`, `updated`, `saved`, `restored` (`restore()` Eloquent сохраняет запись); при ручном порядке запись ставится в конец — ещё одно сохранение со своими `saving`/`saved` |
| Удаление навсегда | `forceDeleting`, `deleting`, `deleted`, `forceDeleted` (`forceDelete()` вызывает `delete()`) |
| Ручной порядок (стрелки, перетаскивание) | `saving`/`saved` у каждой записи, чей номер изменился |
| Смена родителя в дереве | как правка в форме (родитель — поле формы) |
| Быстрый переключатель, `SetToggle` | `saving`/`saved`; `SetToggle` сохраняет только записи, где значение изменилось |
| Импорт из файла | как создание или правка в форме — по строке, каждая в своей транзакции; чанков, обходящих модель, нет |
| Откат из журнала изменений | `saving`/`saved`; запись, удалённую после изменения, откат восстанавливает (`restored`) или создаёт заново (`created`) |
| Вложенные блоки (HasMany, HasOne) | события **дочерней** модели: `saving`/`saved` при записи блока; у HasMany — `deleting`/`deleted` при удалении блока из списка (HasOne блок не удаляет) |
| Связь «многие ко многим» (BelongsToMany) | `sync()`: событий связанной модели нет; события pivot-модели — только если связь описана с `->using(ВашPivot::class)` |
| Страницы настроек | событий нет: настройки пишутся в таблицу `fastleo_settings` без модели |

Observer, вернувший `false` из `saving`/`creating`/`updating`/`deleting`, отменяет запись формы, удаление и строку импорта вместе с их транзакцией; в ручном порядке такая запись просто остаётся на прежнем месте. Если логика сайта зависит от событий (адрес каталога, скрытие связанных записей при удалении раздела), вешайте её на observer модели — так она сработает при любой из операций выше.

## Только просмотр

```php
protected static bool $readonly = true;
```

В разделе нет кнопок «Создать», «Изменить», «Удалить» и «Импорт»; запросы на изменение получают ответ 403 (импорт — 404). Подходит для заявок с сайта, журналов, заказов из внешней системы.

## Корзина

Если модель использует `SoftDeletes`, удалённые записи попадают в корзину раздела (вкладка «Корзина» над списком). Оттуда запись можно восстановить или удалить навсегда. Без `SoftDeletes` запись удаляется сразу, после подтверждения в окне.

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;
}
```

Запись в корзине можно открыть на просмотр (значок глаза в корзине), но не изменить.

## Кеш списка разделов

На каждом запросе fastleo ищет ресурсы в папке. На боевом сайте список кешируется:

```bash
php artisan fastleo:cache     # входит в php artisan optimize
php artisan fastleo:clear     # входит в php artisan optimize:clear
```

Кеш необязателен: без него (на разработке и сразу после `fastleo:clear`) разделы находятся сами, только список ищется в папке на каждом запросе. Поэтому после `fastleo:clear` вернуть кеш обязательно лишь на боевом сайте, где нужна скорость.

После добавления или удаления ресурса на сайте с кешем выполните `php artisan optimize` (или `fastleo:cache`) снова — иначе новый раздел не появится, а удалённый вызовет ошибку «Класс ресурса … не найден».

## Ошибки в описании

Ошибка в описании ресурса останавливает страницу исключением с подсказкой, что исправить: при `APP_DEBUG=true` текст виден на странице, на боевом сайте — ошибка 500 и запись в логе. `php artisan fastleo:doctor` показывает те же ошибки заранее.

На любой странице админки (меню строится из всех ресурсов) и в `fastleo:cache`:

- в ресурсе не указана модель;
- адрес раздела недопустимый, занят админкой или совпадает с другим ресурсом.

При открытии раздела:

- два поля с одним именем, недопустимое имя поля;
- `Grid` или `columnSpan()` вне 1–12, в `Tabs` не только `Tab`.

При сохранении:

- поле ресурса не разрешено `$fillable` модели.

## Дальше

- [Поля](fields/README.md) — все типы полей и общие методы.
- [Раскладка формы](layout.md) — вкладки, секции, колонки, зависимые поля.
- [Проверка значений](validation.md) — правила и сообщения об ошибках.
- [Список записей](list.md) — столбцы, поиск, фильтры, сортировка.
- [Действия](actions.md) — кнопки над записью и над выбранными записями.
- [Права доступа](authorization.md) — Policy модели и ограничение записей.
- [Тесты раздела](testing.md) — как проверить раздел своими тестами.
