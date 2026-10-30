# Работа с Claude

Fastleo сделан так, чтобы раздел админки описывался одним классом, а Claude (или другой ИИ-агент) мог собрать его сам: миграцию, модель, ресурс и тест — и проверить результат командами пакета. Достаточно фразы вроде «сделай раздел Врачи с фото, специальностью и галочкой записи».

## Подключение

```bash
php artisan fastleo:skill     # кладёт skill в .claude/skills/fastleo (обновляется той же командой)
```

- **Skill** — инструкция для Claude Code: порядок работы, правила, команды, список полей. Команда `fastleo:install` кладёт его сама.
- **Laravel Boost.** Сайт с [Laravel Boost](https://laravel.com/docs/boost) подхватывает гайдлайны и skill пакета при `php artisan boost:install` — отдельно ничего копировать не нужно.
- **`vendor/camanru/fastleo/docs/llms.txt`** — сжатый справочник по полям и командам для любых ИИ-агентов.
- **Живое описание сайта** — `php artisan fastleo:inspect --json` (разделы, поля, правила, связи, права).

## Порядок работы

1. Миграция таблицы.
2. Модель: `$fillable` (или атрибут `#[Fillable([...])]`, как в моделях Laravel 13) со **всеми** полями формы, касты (`array` для JSON-полей, enum-каст для `Enum`, `boolean` для галочек), методы связей.
3. Ресурс: `php artisan fastleo:make-resource Doctor --generate` собирает поля по таблице, затем их правят: подписи (`$label`, `$singular`, подписи полей) и иконка по умолчанию английские — переведите; `Image` и `Gallery` получают папку методом `directory('doctors')`.
4. Тест раздела: сначала посмотрите в `composer.json`, Pest у сайта или PHPUnit, и напишите тест тем же инструментом; примеры тестов (вход, создание, права, действия, файлы) — в [тестах разделов](testing.md).
5. `php artisan fastleo:doctor` — ошибок быть не должно. Исправить по подсказкам, повторить.

Пример для фразы «раздел Врачи с фото, специальностью и галочкой записи»:

```php
// database/migrations/…_create_doctors_table.php
Schema::create('doctors', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('specialty');
    $table->string('photo')->nullable();
    $table->boolean('accepts_appointments')->default(true);
    $table->timestamps();
});

// app/Models/Doctor.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Doctor extends Model
{
    protected $fillable = ['name', 'specialty', 'photo', 'accepts_appointments'];

    protected function casts(): array
    {
        return ['accepts_appointments' => 'boolean'];
    }
}

// app/Fastleo/DoctorResource.php (адрес раздела — /fastleo/doctors)
namespace App\Fastleo;

use App\Models\Doctor;
use Fastleo\Fields\Image;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;

final class DoctorResource extends Resource
{
    protected static string $model = Doctor::class;

    protected static ?string $label = 'Врачи';

    protected static ?string $singular = 'Врач';

    public function fields(): array
    {
        return [
            Text::make('name', 'Имя')->required()->searchable()->sortable(),
            Text::make('specialty', 'Специальность')->required(),
            Image::make('photo', 'Фото')->directory('doctors'),
            Toggle::make('accepts_appointments', 'Запись открыта'),
        ];
    }
}

// tests/Feature/DoctorsAdminTest.php — PHPUnit (стандарт нового Laravel; у сайта с Pest — тот же код в виде it(...))
namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_doctors(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['fastleo_admin' => true])->save();
        $this->actingAs($admin, 'fastleo');

        $this->get(route('fastleo.resources.index', 'doctors'))->assertOk();
        $this->get(route('fastleo.resources.create', 'doctors'))->assertOk();
        $this->post(route('fastleo.resources.store', 'doctors'), ['name' => 'Иванов', 'specialty' => 'Терапевт'])->assertRedirect();

        $doctor = Doctor::where('name', 'Иванов')->firstOrFail();
        $this->get(route('fastleo.resources.edit', ['doctors', $doctor->id]))->assertOk();
    }
}
```

## Правила

- Раздел — один класс в `app/Fastleo` (`fastleo.resources.path`). Своих контроллеров, маршрутов и форм для CRUD не пишут.
- В форме и при записи участвуют только поля из `fields()`: новая колонка в таблице сама не появляется.
- Каждое поле, которое пишется из формы, должно быть в `$fillable` модели. `fastleo_admin` в `$fillable` не добавляют никогда.
- Запись идёт через Eloquent (`fill()->save()`), поэтому работают касты, мутаторы и observers модели. Свою логику сайта вешают на observers, а не на пакет.
- Права задаёт Policy модели; нет Policy — доступ полный. Раздел только для просмотра: `protected static bool $readonly = true`.
- Поле выбирают по [таблице полей](fields/README.md#типы): у каждого поля есть страница с колонкой, правилами и примером.
- Файлы и картинки хранятся путём относительно диска (`uploads/doctors/ivanov.jpg`), галерея — JSON-массивом (нужен каст `array`).
- Пароли и секреты не показывают полями (кроме `Password`, который хеширует значение).
- «Галочка» (да/нет) — `Toggle`; `Checkbox` нужен, когда флажок стоит в строке с текстом.
- Для тестов имена маршрутов: `fastleo.resources.index`, `.create`, `.store`, `.show`, `.edit`, `.update`, `.destroy` (адрес раздела — первым параметром, ключ записи — вторым: `route('fastleo.resources.edit', ['doctors', $id])`). Вход в тесте — `actingAs($admin, 'fastleo')`, администратор — пользователь с `fastleo_admin = true`.
- Внешние ключи: миграция таблицы, на которую ссылаются (рубрики), должна выполняться раньше (метка времени меньше), чем миграция таблицы со ссылкой (новости).
- После каждой правки — `fastleo:doctor`; перед сдачей работы — тест раздела.

## Частые ошибки

| Симптом | Причина | Исправление |
|---|---|---|
| `fastleo:doctor`: «не в $fillable» | поле есть, а в `$fillable` модели нет | добавить имя в `$fillable` |
| «хранит массив … нет каста array» | Gallery, Tags, KeyValue, Repeater, Blocks, Seo, `Select::multiple()` или `translatable()` | `'поле' => 'array'` в `casts()` модели |
| «Enum требует каст» | Enum-поле без enum-каста | `'status' => Status::class` в `casts()` |
| «Связь поля … метод» | `BelongsTo::make('category_id')` без метода `category()` | добавить метод связи или `->relation('имя')` |
| «пишет в колонку, которой нет» | поле без колонки | миграция или убрать поле |
| ошибка базы при пустом поле | NOT NULL без значения по умолчанию, поле не `required()` | `required()` или `default()` в миграции |
| админка без стилей | ассеты не опубликованы | `php artisan vendor:publish --tag=fastleo-assets --force` |
| картинки не открываются | нет `public/storage` | `php artisan storage:link` |
| новый раздел не виден | устарел кеш списка разделов | `php artisan fastleo:cache` (или `optimize:clear`) |
| 404 по адресу раздела | адрес берётся из имени модели во множественном числе (`Doctor` → `doctors`; у неисчисляемых слов он не меняется: `News` → `news`) | посмотреть адрес в `fastleo:inspect` или задать `protected static ?string $slug = 'doctors'` |

## Команды

### `fastleo:inspect`

Показывает, как сайт настроил админку: разделы, поля, правила проверки, связи, фильтры, действия и Policy.

```bash
php artisan fastleo:inspect            # все разделы, настройки и страницы
php artisan fastleo:inspect articles   # один раздел по адресу
php artisan fastleo:inspect --json     # то же для машины
```

В JSON у раздела есть `slug`, `class`, `label`, `singular`, `group`, `model`, `table`, `policy` (класс Policy или `null` — тогда доступ полный), `readonly`, `exportable`, `importable`, `import_key`, `soft_deletes`, `sort_column`, `tree_column`, `fields`, `filters`, `actions`. У поля — `name`, `type`, `label`, `required`, `readonly`, `multiple`, `translatable`, `in_form`, `in_list`, `searchable`, `sortable`, `writes_attribute`, `rules` (правила проверки по ключам запроса) и сведения по типу: `relation` и `related_model` у связей, `folder` и `extensions` у файлов, `options` у `Select` (`null`, если вариантов больше 50), `max_length` у текстовых, `quick_toggle: true` у галочки с `quickToggle()`, вложенные `fields` у блоков и повторителей. `fastleo:inspect <адрес>` для одного раздела отдаёт тот же объект раздела без обёртки `resources`.

Если раздел описан с ошибкой, вместо `fields` будет `error` с текстом — остальные разделы выводятся как обычно.

### `fastleo:doctor`

Проверяет сайт и описанные разделы. Ошибки — раздел или админка не заработают, код выхода `1`. Предупреждения — работает, но не так, как задумано, код выхода `0`.

```bash
php artisan fastleo:doctor
php artisan fastleo:doctor --json   # {"ok": false, "errors": 2, "warnings": 1, "problems": [...]}
```

В каждой проблеме: `level` (`error` или `warning`), `scope` (адрес раздела, `site`, `registry` для общего списка разделов или `раздел › поле` для поля вложенного блока), `message` и `hint` — что сделать (или `null`, если подсказки нет).

**Ошибки:**

| Проверка | Что делать |
|---|---|
| guard `fastleo` не на драйвере `session` | убрать описание guard из `config/auth.php` |
| модель пользователей не подходит для админки | трейт `HasFastleoAccess` и интерфейс `FastleoUser` |
| нет таблиц пакета или колонки `fastleo_admin` | `php artisan migrate` |
| ассеты не опубликованы | `php artisan vendor:publish --tag=fastleo-assets --force` |
| список разделов не собирается (повтор адреса, неверный `$sortColumn`…) | исправить ресурс по тексту ошибки |
| раздел описан с ошибкой (повтор поля, нет модели) | исправить ресурс |
| нет таблицы модели | миграция |
| поле пишет в колонку, которой нет | миграция или убрать поле |
| поле не в `$fillable` модели | добавить в `$fillable` или `readonly()` |
| связь поля без метода в модели | добавить метод связи |
| JSON-поле (Gallery, Tags, KeyValue, Repeater, Blocks, Seo, `Select::multiple()`, `translatable()`) без каста `array` | добавить каст в модель |
| `Enum` без enum-каста | добавить каст в модель |
| колонка `$sortColumn` или `$treeColumn` не найдена | исправить имя или миграцию |
| `SetToggle` указывает на поле, которого нет в ресурсе или которое не `Checkbox`/`Toggle` | поправить имя поля |

**Предупреждения:**

| Проверка | Что делать |
|---|---|
| очередь `sync` | `QUEUE_CONNECTION=database` и `php artisan queue:work` |
| встроенный раздел («Каналы», «Публикации», «Уведомления») не появился: его адрес (`channels`, `publications`, `notification-bots`) занят разделом сайта | задать разделу сайта другой `$slug` или отключить встроенный раздел (`null` в `config/fastleo.php`) |
| нет ссылки `public/storage` | `php artisan storage:link` |
| ассеты устарели | опубликовать заново |
| кеш `bootstrap/cache/fastleo.php` устарел | `php artisan fastleo:cache` |
| колонка NOT NULL без значения по умолчанию, а поле не `required()` | `required()` у поля или `default()` в миграции |
| `maxLength()` больше длины колонки, у `Textarea` — длина не ограничена (только MySQL и PostgreSQL) | уменьшить или задать `maxLength()`, либо увеличить колонку |
| `min()`/`max()` числа шире колонки (`int`, `decimal`; не SQLite) | уменьшить границы или увеличить колонку |
| варианты `Select` не входят в enum-колонку MySQL | привести `options()` в соответствие с колонкой |
| форма раздела может отправить больше полей, чем `max_input_vars` PHP | уменьшить `max()` вложенных полей или увеличить `max_input_vars` (проверка идёт по настройке php-cli) |
| настройка `fastleo.rich_text` недопустима (ошибка) | убрать из неё `on*`, `srcdoc`, опасные теги, исправить адреса `iframe_hosts` |
| у записей с RichText при сохранении пропадут теги, атрибуты или плееры | разрешить их в `fastleo.rich_text` до первого сохранения старых записей |
| у поля с `quickToggle()` стоит `readonly()`, `onlyInList()` или `hideOnEdit()` — переключатель не появится | убрать лишнее у поля или `quickToggle()` |
| `SetToggle` указывает на поле `readonly()` — действие пропустит все записи | убрать `readonly()` или действие |
| включён `fastleo.robots`, а в `public` лежит свой `robots.txt` | удалить файл или выключить `fastleo.robots.enabled` |
| на боевом сайте планировщик не запускался дольше 10 минут | cron: `* * * * * php artisan schedule:run`; метка запуска хранится в кеше приложения |
| подключение к SoftNetica: недопустимый адрес (ошибка), нет хостов кабинета, отчёты не доходят дольше 30 минут | исправить `softnetica.url`/`cabinet_hosts`, запустить планировщик |

Проверка NOT NULL пропускает поля, которые заполняются сами: галочки, пароль, адрес `Slug` с `from()` (без `from()` адрес сам не заполнится), Gallery, KeyValue, Seo, поля со значением `default()`, колонки со значением по умолчанию в базе или в `$attributes` модели, а также разделы только для чтения.

### `fastleo:make-*`

Заготовки для своих классов сайта: класс ставится в `app/Fastleo` (`fastleo.resources.path`), шаблон — в `resources/views`. Команда не перезаписывает существующие файлы без `--force`, в конце подсказывает, куда подключить класс.

| Команда | Что создаёт | Куда подключить |
|---|---|---|
| `fastleo:make-resource Doctor [--generate]` | ресурс (`--generate` — поля по таблице) | находится сам |
| `fastleo:make-action PublishDoctors` | `Actions/PublishDoctors.php` | `actions()` ресурса: [actions.md](actions.md) |
| `fastleo:make-filter ByCity` | `Filters/ByCity.php` + шаблон | `filters()` ресурса: [list.md](list.md) |
| `fastleo:make-widget QueueSize` | `Widgets/QueueSize.php` + шаблон | `widgets()` класса `Dashboard`: [dashboard.md](dashboard.md) |
| `fastleo:make-page Report` | `ReportPage.php` + шаблон | находится сама: [pages.md](pages.md) |
| `fastleo:make-field Phone` | `Fields/Phone.php` + шаблон | `fields()` ресурса: [fields/custom.md](fields/custom.md) |
| `fastleo:make-settings Contacts` | `ContactsSettings.php` со страницей настроек | находится сама: [settings.md](settings.md) |
| `fastleo:make-dashboard` | `Dashboard.php` с набором виджетов | находится сам: [dashboard.md](dashboard.md) |
| `fastleo:make-policy Doctor [--model=…]` | `app/Policies/DoctorPolicy.php` со всеми способностями, которые спрашивает админка (везде `true`) | находится по правилам Laravel: [authorization.md](authorization.md) |

## MCP-сервер

Через MCP Claude читает и правит записи самой админки — «покажи последние обращения», «исправь заголовок у статьи 12», «опубликуй все черновики». Сервер работает от имени одного администратора, с его правами: Policy моделей, `readonly`-разделы и проверка значений — те же, что в админке. Правки попадают в [журнал изменений](activity.md) под его именем.

Подключение — в `.mcp.json` в корне сайта:

```json
{
  "mcpServers": {
    "fastleo": {
      "command": "php",
      "args": ["artisan", "fastleo:mcp", "--user=admin@site.ru"]
    }
  }
}
```

`--user` — email администратора (колонка `fastleo_admin` или ваш `canAccessFastleo()`). Нет такого пользователя или нет доступа — сервер не запускается. Сервер локальный (stdio): он запускается клиентом на машине, где лежит сайт, и по сети не открывается. Запускать `fastleo:mcp` вручную в терминале не нужно — он ждёт сообщений клиента.

| Инструмент | Что делает |
|---|---|
| `list-resources` | разделы, к которым есть доступ |
| `describe-resource` | поля, правила проверки, связи, фильтры, действия (то же, что `fastleo:inspect`) |
| `list-records` | записи раздела: `q` (поиск), `filters`, `sort`, `direction`, `page`, `per_page` (до 100) |
| `get-record` | запись со всеми значениями формы |
| `create-record` | новая запись: `values` — значения полей |
| `update-record` | изменение записи: в `values` — только меняемые поля, остальные не трогаются; списки заменяются целиком (см. ниже) |
| `delete-record` | удаление (в корзину, если у модели `SoftDeletes`) — только с `--allow-delete` |
| `run-action` | действие раздела над записями `ids` (и `data` для его мини-формы); если действие отвечает файлом, оно выполняется, а файл не передаётся |

Сервер заново читает администратора при каждом вызове: если у него сняли доступ после запуска, инструменты перестают работать сразу. `create-record` требует права на открытие раздела (`viewAny`) и на создание. Пароль с подтверждением (`Password::confirmed()`) достаточно прислать один раз. Если при частичном изменении меняется поле, от которого зависит другое (`dependsOn()`), зависимое поле проверяется с его сохранённым значением.

Правила записи те же, что у формы: значение проверяется правилами поля, пишутся только поля из `fields()`, доступные для изменения (поле `readonly()` или скрытое — ошибка «нельзя записать»), файловые поля принимают пути вида `uploads/…` (формат и расширение проверяются; существует ли файл на диске, не проверяется) — загрузка файлов через MCP недоступна, файл кладут через [файловый менеджер](files.md). Ошибки проверки приходят по полям.

**Списки при изменении.** Переводы `translatable()` дополняются: присланный язык заменяется, остальные сохраняются. А списки — вложенные записи (HasMany, HasOne), Repeater, Blocks, Gallery, Tags, KeyValue, связи «многие ко многим» и `Select::multiple()` — заменяются целиком: передавайте весь список так, как вернул `get-record` (у вложенных записей — вместе с их `id`). Если в присланном списке нет существующей вложенной записи, а сервер запущен без `--allow-delete`, это ошибка — записи не удаляются.

**Удаление выключено.** Инструмент `delete-record` и встроенные действия удаления доступны, только если сервер запущен с ключом `--allow-delete` (или `'mcp' => ['allow_delete' => true]` в [конфиге](configuration.md)). Права на удаление всё равно проверяет Policy; запись с вложенными (дерево) не удаляется.

**Закрыто от агента:** разделы с данными людей — по умолчанию сервер не видит раздел, в котором есть поле `Email` или `Tel` или поле со словом `email`, `phone`, `tel`, `telephone`, `cellphone`, `whatsapp`, `viber` в имени (`client_phone`, `phone2`, `phones`, `phoneNumber`, `PHONE`; галочки, файлы и даты по имени не проверяются; заявки, обращения, клиенты, администраторы; поле внутри `HasMany`, `Repeater` и `Blocks` тоже считается). Открыть такой раздел можно только явно: `protected static bool $mcpPersonalData = true;` в ресурсе (для «Администраторов» — в своём наследнике `UserResource` вместе с `$mcp = true`). Закрыть любой другой раздел — `protected static bool $mcp = false;`. Какие разделы видит агент и почему закрыт остальной — в `fastleo:inspect` (строка `mcp`). Всегда закрыты: разделы с `protected static bool $mcp = false` (встроенные «Уведомления» и «Каналы» с токенами ботов) и действия с `protected bool $mcp = false` («Отправить тест» и все действия публикаций, которые что-то делают в мессенджерах: отправить, повторить, обновить, удалить из каналов). Черновик публикации агент создать и поправить может, в том числе действием `Publish` ([публикации](publishing.md#кнопка-в-разделе-сайта)).

**Чего нет:** загрузки файлов, действий над корзиной, передачи файла, который вернуло действие (экспорт), доступа по сети.
