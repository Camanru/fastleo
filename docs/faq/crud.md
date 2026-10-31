# FAQ: Разделы и поля (CRUD)

Как создать раздел админки, подобрать поля, настроить форму, связи и сохранение. Все вопросы — [список](README.md).

## Новый раздел

### Как создать раздел с нуля?

Создайте миграцию, модель с `$fillable` и класс-ресурс в `app/Fastleo` — регистрировать его не нужно.

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

#[Fillable(['name', 'specialty', 'is_active'])]
class Doctor extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
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

    public function fields(): array
    {
        return [
            Text::make('name', 'Имя')->required()->searchable()->sortable(),
            Text::make('specialty', 'Специальность'),
            Toggle::make('is_active', 'Показывать на сайте'),
        ];
    }
}
```

Затем `php artisan migrate` и `php artisan fastleo:doctor` — ошибок быть не должно. Раздел откроется по адресу `/fastleo/doctors`.

Подробнее — [Разделы](../resources.md#первый-раздел).

### Можно ли собрать ресурс по готовой таблице?

Да. Выполните миграцию, затем команду с `--generate` — поля подберутся по колонкам таблицы.

```bash
php artisan fastleo:make-resource Doctor --generate
php artisan fastleo:make-resource Doctor --model="App\Models\Staff\Doctor"   # модель не в App\Models
```

После генерации:

1. Переведите подписи: `$label`, `$singular` и вторые аргументы `make()` — они получаются английскими из имён колонок.
2. Добавьте вручную связи `BelongsToMany`, `HasMany`, `HasOne` — по таблице их не видно.
3. У `Image` и `Gallery` задайте папку: `->directory('doctors')`.
4. Проверьте `php artisan fastleo:doctor`.

Подробнее — [Команда make-resource](../resources.md#команда-fastleomake-resource).

### Как поменять адрес раздела?

Задайте `$slug`: латиница в нижнем регистре, цифры и дефис. По умолчанию адрес — имя модели во множественном числе (`Doctor` → `doctors`).

```php
// app/Fastleo/DoctorResource.php
protected static ?string $slug = 'staff';   // /fastleo/staff
```

Адреса самой админки (`login`, `files`, `settings`, `pages`, `search` и другие) занимать нельзя. Текущий адрес раздела покажет `php artisan fastleo:inspect`.

Подробнее — [Свойства ресурса](../resources.md#свойства).

### Как задать название раздела, кнопку «Создать» и иконку в меню?

Задайте статические свойства ресурса. Иконка — имя из [Lucide](https://lucide.dev/icons).

```php
// app/Fastleo/DoctorResource.php
protected static ?string $label = 'Врачи';                  // заголовок и пункт меню
protected static ?string $singular = 'Врач';                // «Создать: Врач»
protected static ?string $createLabel = 'Добавить врача';   // кнопка в списке
protected static ?string $description = 'Врачи видны на странице «Команда»';
protected static string $icon = 'stethoscope';
protected static ?string $group = 'Клиника';                // группа меню
protected static int $position = 10;                        // порядок в группе
```

Порядок групп меню и прочее про меню — [FAQ: интерфейс админки](admin-ui.md).

Подробнее — [Свойства ресурса](../resources.md#свойства).

### Как сделать название записи в заголовке формы и хлебных крошках?

Поставьте поле с названием первым в `fields()` или переопределите `title()`.

```php
// app/Fastleo/DoctorResource.php
use Illuminate\Database\Eloquent\Model;

public function title(Model $record): string
{
    return $record->name.' — '.$record->specialty;
}
```

Подробнее — [title()](../resources.md#title).

## Выбор поля

### Какое поле выбрать под задачу?

Найдите задачу в таблице — у каждого поля есть страница с колонкой для миграции и примером.

| Задача | Поле | Колонка |
|---|---|---|
| Название, ФИО | `Text` | `string` |
| Анонс, заметка | `Textarea` | `text` |
| Статья с форматированием | `RichText` | `longText` |
| Цена, количество | `Number` (`->integer()` для целых) | `integer` / `decimal` |
| Да/нет | `Toggle` | `boolean` |
| Статус из списка | `Select` или `Enum` | `string` |
| Дата / дата и время | `Date` / `DateTime` | `date` / `dateTime` |
| Телефон, email, сайт | `Tel`, `Email`, `Url` | `string` |
| Адрес страницы из заголовка | `Slug::make('slug')->from('title')` | `string`, уникальная |
| Метки свободным вводом | `Tags` | `json` + каст `array` |
| Категория записи | `BelongsTo` | `foreignId` |
| Характеристики «название — значение» | `KeyValue` | `json` + каст `array` |
| Фото, файл, галерея | `Image`, `File`, `Gallery` — [FAQ: файлы](files.md) | `string` / `json` |

Подробнее — [Типы полей](../fields/README.md#типы).

### Как сделать поле обязательным?

Вызовите `required()`. Для галочки «Согласен» `required()` не требует отметки — добавьте правило `accepted`.

```php
// app/Fastleo/DoctorResource.php
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\Text;

Text::make('name', 'Имя')->required(),
Checkbox::make('agree', 'Согласие на обработку данных')->rules(['accepted']),
```

Колонка обязательного поля в миграции — без `nullable()`.

Подробнее — [Общие методы](../fields/README.md#общие-методы).

### Как проверить уникальность и показать ссылку на дубль?

Добавьте правило `Rule::unique(...)->ignore($record)` и `duplicateOf()` — под ошибкой появится «Открыть: …» со ссылкой на найденную запись.

```php
// app/Fastleo/ProductResource.php
use App\Models\Product;
use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

Text::make('sku', 'Артикул')
    ->required()
    ->rules(fn (?Model $record) => [Rule::unique('products', 'sku')->ignore($record)])
    ->duplicateOf(fn (string $sku) => Product::query()->where('sku', $sku)->first()),
```

```php
// миграция
$table->string('sku')->unique();
```

Подробнее — [Свои правила](../validation.md#свои-правила).

### Как задать значение по умолчанию в новой записи?

Вызовите `default()` у поля или задайте `default()` колонке в миграции — форма создания возьмёт его сама.

```php
// app/Fastleo/OrderResource.php
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Select;
use Fastleo\Fields\Toggle;

Toggle::make('is_active', 'Активен')->default(true),
Select::make('status', 'Статус')->options(['new' => 'Новый', 'paid' => 'Оплачен'])->default('new'),
DateTime::make('ordered_at', 'Дата заказа')->default(fn () => now()),
```

Значение `default()` поля подставляется и тогда, когда поля нет в запросе (импорт без столбца). Значение из миграции только предзаполняет форму.

Подробнее — [Значение по умолчанию](../fields/README.md#значение-по-умолчанию).

### Как записать 0 или пустую строку вместо NULL?

Задайте `emptyValue()` — пригодится для колонок NOT NULL и для сайта, где «нет значения» хранится нулём.

```php
// app/Fastleo/ProductResource.php
use App\Models\Category;
use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Textarea;

Select::make('category_id', 'Категория')
    ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id')->all())
    ->placeholder('Без категории')
    ->emptyValue(0),
Number::make('discount', 'Скидка, %')->integer()->emptyValue(0),
Textarea::make('note', 'Заметка')->emptyValue(''),
```

У галочек, паролей, файлов, JSON-полей, связей «многие ко многим» (`BelongsToMany`) и вложенных записей (`HasMany`, `HasOne`) `emptyValue()` недоступен. У `BelongsTo` он работает ([пустое значение](../fields/README.md#пустое-значение)).

Подробнее — [Пустое значение](../fields/README.md#пустое-значение).

### Как скрыть поле при создании, при правке или для части администраторов?

Используйте `hideOnCreate()`, `hideOnEdit()` или `hidden()` с замыканием. Скрытое поле не проверяется и не записывается.

```php
// app/Fastleo/ProductResource.php
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Model;

// Артикул задаётся при создании; на правке его нет в форме, на просмотре он виден
Text::make('sku', 'Артикул')->required()->hideOnEdit(),

// Счётчик заполняется позже — при создании его нет
Number::make('rating', 'Рейтинг')->hideOnCreate(),

// Закупочная цена видна только старшим (is_super — колонка вашей модели пользователя)
Number::make('cost', 'Закупка')->hidden(fn (?Model $record) => ! auth('fastleo')->user()?->is_super),
```

В замыкание `hidden()` приходит `null` при создании и в списке — пишите `$record?->…`. Полный запрет действий по ролям — [FAQ: права](access.md).

Подробнее — [Общие методы](../fields/README.md#общие-методы).

### Как показать поле только для чтения?

Вызовите `readonly()` — значение видно, но не меняется и не записывается. В `$fillable` такое поле добавлять не нужно.

```php
// app/Fastleo/OrderResource.php
use Fastleo\Fields\Text;

Text::make('external_id', 'Номер во внешней системе')->readonly(),
```

`disabled()` делает то же, но поле выглядит серым.

Подробнее — [Общие методы](../fields/README.md#общие-методы).

## Раскладка формы

### Как разбить форму на вкладки, секции, колонки и боковую панель?

Оберните поля в `Tabs`/`Tab`, `Section`, `Grid` и `Aside`. На список и запись в базу раскладка не влияет.

```php
// app/Fastleo/ProductResource.php
use Fastleo\Fields\Date;
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Layout\Aside;
use Fastleo\Layout\Grid;
use Fastleo\Layout\Section;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;

public function fields(): array
{
    return [
        Tabs::make([
            Tab::make('Основное', [
                Text::make('title', 'Название')->required(),
                Grid::make(3, [
                    Number::make('price', 'Цена')->min(0)->step(0.01),
                    Number::make('stock', 'Остаток')->integer(),
                    Text::make('sku', 'Артикул'),
                ]),
            ]),
            Tab::make('Описание', [
                Section::make('Для сайта', [
                    Textarea::make('excerpt', 'Анонс'),
                ])->description('Показывается в карточке товара')->collapsible(),
            ]),
        ]),
        Aside::make([
            Toggle::make('is_active', 'Показывать на сайте'),
            Date::make('available_from', 'В продаже с'),
        ]),
    ];
}
```

Имена полей уникальны во всей форме, даже в разных вкладках. Ширина поля в сетке — `->columnSpan(2)`.

Подробнее — [Раскладка формы](../layout.md).

### Как показать поле только при определённом значении другого поля?

Вызовите `visibleWhen('поле', значение)`. Скрытое поле не проверяется и не сохраняется, поэтому `required()` ему не мешает.

```php
// app/Fastleo/ArticleResource.php
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Select;
use Fastleo\Fields\Url;

Select::make('type', 'Вид')->options(['news' => 'Новость', 'video' => 'Видео', 'promo' => 'Акция']),
Url::make('video_url', 'Ссылка на видео')->required()->visibleWhen('type', 'video'),
DateTime::make('promo_until', 'Акция до')->visibleWhen('type', ['promo']),

Checkbox::make('is_featured', 'На главной'),
DateTime::make('featured_from', 'На главной с')->visibleWhen('is_featured', true),
```

Управляющее поле — с одним значением: `Select`, `Radio`, `Enum`, галочка, `BelongsTo`, текст.

Подробнее — [Зависимые поля](../layout.md#зависимые-поля).

### Как сделать зависимые списки «марка → модель»?

Вызовите `dependsOn('поле')`: у `Select` замыкание вариантов получает значение зависимости, у `BelongsTo` — запрос и значение.

```php
// app/Fastleo/CarResource.php
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Select;
use Illuminate\Database\Eloquent\Builder;

// Варианты из массива
Select::make('brand', 'Марка')->options(['bmw' => 'BMW', 'kia' => 'Kia']),
Select::make('model', 'Модель')
    ->options(fn (?string $brand) => match ($brand) {
        'bmw' => ['x5' => 'X5', 'm3' => 'M3'],
        'kia' => ['rio' => 'Rio'],
        default => [],
    })
    ->dependsOn('brand'),

// Варианты из таблиц: car_models.brand_id
BelongsTo::make('brand_id', 'Марка'),
BelongsTo::make('car_model_id', 'Модель')
    ->dependsOn('brand_id', fn (Builder $query, mixed $brand) => $query->where('brand_id', $brand ?? 0)),
```

Модель другой марки сохранить нельзя — проверка берёт марку из той же формы. Внутри вложенных блоков зависимые списки не работают.

Подробнее — [Зависимые списки](../layout.md#зависимые-списки).

## Связи и вложенные данные

### Как выбрать категорию у товара (BelongsTo)?

Добавьте внешний ключ, метод связи в модели и поле `BelongsTo` с именем колонки.

```php
// миграция
$table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
```

```php
// app/Models/Product.php
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'category_id'])]
class Product extends Model
{
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
```

```php
// app/Fastleo/ProductResource.php
use Fastleo\Fields\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

BelongsTo::make('category_id', 'Категория')
    ->required()
    ->titleColumn('name')                                            // подпись варианта
    ->query(fn (Builder $query) => $query->where('is_active', true)), // только активные
```

Миграция таблицы `categories` должна выполняться раньше миграции `products`. Если метод связи называется иначе, чем колонка без `_id`, — `->relation('имя')`.

Подробнее — [BelongsTo](../fields/belongs-to.md).

### Как привязать к врачу несколько услуг (BelongsToMany)?

Создайте промежуточную таблицу, метод `belongsToMany` и поле с именем метода. В `$fillable` ничего добавлять не нужно.

```php
// миграция
Schema::create('doctor_service', function (Blueprint $table) {
    $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
    $table->foreignId('service_id')->constrained()->cascadeOnDelete();
    $table->primary(['doctor_id', 'service_id']);
});
```

```php
// app/Models/Doctor.php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

public function services(): BelongsToMany
{
    return $this->belongsToMany(Service::class);
}
```

```php
// app/Fastleo/DoctorResource.php
use Fastleo\Fields\BelongsToMany;

BelongsToMany::make('services', 'Услуги'),
```

Дополнительные колонки промежуточной таблицы (`price`, `sort`) поле не редактирует — для них сделайте отдельную модель и `HasMany`.

Подробнее — [BelongsToMany](../fields/belongs-to-many.md).

### Как редактировать дочерние записи прямо в форме (HasMany, HasOne)?

Для списка записей — `HasMany` с полями блока, для одной записи — `HasOne`. Имя поля — метод связи модели.

```php
// миграция
Schema::create('doctor_prices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
    $table->string('title');
    $table->decimal('price', 10, 2)->nullable();
    $table->unsignedInteger('sort')->default(0);
    $table->timestamps();
});
```

```php
// app/Models/Doctor.php
use Illuminate\Database\Eloquent\Relations\HasMany;

public function prices(): HasMany
{
    return $this->hasMany(DoctorPrice::class);
}
```

```php
// app/Models/DoctorPrice.php
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'price'])]   // doctor_id и sort пишет пакет
class DoctorPrice extends Model {}
```

```php
// app/Fastleo/DoctorResource.php
use Fastleo\Fields\HasMany;
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Layout\Grid;

HasMany::make('prices', 'Цены')
    ->fields([
        Grid::make(2, [
            Text::make('title', 'Услуга')->required(),
            Number::make('price', 'Цена')->min(0),
        ]),
    ])
    ->orderColumn('sort')   // перетаскивание блоков
    ->max(50),
```

`HasOne` описывается так же (`HasOne::make('banner', 'Баннер')->fields([...])`), а у его таблицы внешний ключ `->unique()`. Внутри блоков нельзя связи, `Slug`, `Password` и вложенные блоки. Если дочерних записей много или у них свои действия — покажите их таблицей под формой: [связанные таблицы](list.md#как-показать-связанные-записи-на-странице-записи).

Подробнее — [HasMany](../fields/has-many.md), [HasOne](../fields/has-one.md).

### Как хранить список «вопрос — ответ» или характеристики без отдельной таблицы?

Возьмите `Repeater` для группы полей и `KeyValue` для пар «название — значение». Колонка `json` и каст `array` обязательны.

```php
// миграция
$table->json('faq')->nullable();
$table->text('specs')->nullable();   // text, чтобы MySQL не сортировал ключи
```

```php
// app/Models/Product.php
protected function casts(): array
{
    return ['faq' => 'array', 'specs' => 'array'];
}
```

```php
// app/Fastleo/ProductResource.php
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;

Repeater::make('faq', 'Вопросы и ответы')
    ->fields([
        Text::make('question', 'Вопрос')->required(),
        Textarea::make('answer', 'Ответ'),
    ])
    ->max(20),

KeyValue::make('specs', 'Характеристики')->keyLabel('Параметр')->valueLabel('Значение'),
```

На сайте: `@foreach ($product->faq ?? [] as $item) {{ $item['question'] }} @endforeach`. Если элементы нужно искать или фильтровать — отдельная таблица и `HasMany`.

Подробнее — [Repeater](../fields/repeater.md), [KeyValue](../fields/key-value.md).

### Какие касты нужны JSON-полям?

Добавьте `'array'` в `casts()` для каждого поля, которое хранит массив: `Gallery`, `Tags`, `KeyValue`, `Repeater`, `Blocks`, `Seo`, `Select::multiple()` и любое поле с `translatable()`. Без каста сохранение остановится с ошибкой, а `fastleo:doctor` покажет поле.

```php
// миграция
$table->json('tags')->nullable();
$table->json('days')->nullable();
```

```php
// app/Models/Doctor.php
protected function casts(): array
{
    return [
        'tags' => 'array',   // Tags::make('tags')
        'days' => 'array',   // Select::make('days')->multiple()
    ];
}
```

Подробнее — [Типы полей](../fields/README.md#типы).

### Как сделать статус из PHP enum?

Опишите backed enum с `label()`, добавьте enum-каст в модель и поле `Enum`.

```php
// app/Enums/OrderStatus.php
namespace App\Enums;

enum OrderStatus: string
{
    case New = 'new';
    case Paid = 'paid';
    case Shipped = 'shipped';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новый',
            self::Paid => 'Оплачен',
            self::Shipped => 'Отправлен',
        };
    }
}
```

```php
// app/Models/Order.php
use App\Enums\OrderStatus;

protected function casts(): array
{
    return ['status' => OrderStatus::class];
}
```

```php
// app/Fastleo/OrderResource.php
use App\Enums\OrderStatus;
use Fastleo\Fields\Enum;

Enum::make('status', 'Статус')->enum(OrderStatus::class)->required()->default(OrderStatus::New->value),
```

Колонка — `$table->string('status', 20)`. Прежде чем убрать вариант из enum, обновите записи с ним в базе. Цветные метки в списке — [FAQ: список](list.md#как-показать-статусы-цветными-метками).

Подробнее — [Enum](../fields/enum.md).

### Как хранить пароль и ключ API?

Пароль — поле `Password` (пишет хеш), ключ чужого сервиса — `Secret` (пишет как есть, шифрует каст `encrypted`). Оба в форме всегда пустые; пустое поле при правке оставляет старое значение.

```php
// миграция
$table->string('password');
$table->text('api_key')->nullable();
```

```php
// app/Models/Partner.php
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'password', 'api_key'])]
#[Hidden(['password', 'api_key'])]
class Partner extends Model
{
    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }
}
```

```php
// app/Fastleo/PartnerResource.php
use Fastleo\Fields\Password;
use Fastleo\Fields\Secret;

Password::make('password', 'Пароль')->required()->confirmed(),
Secret::make('api_key', 'Ключ API'),
```

Требования к паролю — `Password::defaults()` в `AppServiceProvider`. На странице настроек `Secret` не работает (как и `Password`) — секрет там хранится текстовым полем с `encrypted()`: [секреты настроек](../settings.md#секреты).

Подробнее — [Password](../fields/password.md), [Secret](../fields/secret.md).

### Как сделать многоязычные поля?

Перечислите языки в `config/fastleo.php`, вызовите `translatable()` у поля, колонку сделайте `json` с кастом `array` (или подключите spatie/laravel-translatable).

```php
// config/fastleo.php
'locales' => ['ru', 'en'],
```

```php
// миграция
$table->json('title');
$table->json('body')->nullable();
```

```php
// app/Models/Page.php
protected function casts(): array
{
    return ['title' => 'array', 'body' => 'array'];
}
```

```php
// app/Fastleo/PageResource.php
use Fastleo\Fields\RichText;
use Fastleo\Fields\Text;

Text::make('title', 'Заголовок')->translatable()->required()->searchable(),
RichText::make('body', 'Текст')->translatable(),
```

`required()` требует только первый язык. На сайте без spatie: `$page->title[app()->getLocale()] ?? $page->title['ru']`. `translatable()` есть у `Text`, `Textarea`, `Markdown`, `Code` и `RichText`.

Подробнее — [Многоязычные поля](../translations.md).

### Как сделать своё поле?

Создайте заготовку командой — появятся класс-наследник `Text` и Blade-шаблон. Свою проверку задайте в `typeRules()`, обработку значения перед записью — в `dehydrate()`.

```bash
php artisan fastleo:make-field Phone
```

```php
// app/Fastleo/Fields/Phone.php
namespace App\Fastleo\Fields;

use Fastleo\Fields\Text;
use Override;

final class Phone extends Text
{
    #[Override]
    public function view(): string
    {
        return 'fastleo.fields.phone';
    }

    #[Override]
    protected function typeRules(): array
    {
        // (?=.*\d) — хотя бы одна цифра: «(--)» не пройдёт проверку и не станет пустой строкой в базе
        return ['string', 'regex:/^(?=.*\d)[\d\s()+-]{6,32}$/'];
    }

    /** В базу — только цифры: «+7 (900) 123-45-67» → «79001234567». */
    #[Override]
    public function dehydrate(mixed $value): mixed
    {
        $value = parent::dehydrate($value);

        return is_string($value) ? preg_replace('/\D/', '', $value) : $value;
    }
}
```

```php
// app/Fastleo/ClientResource.php
use App\Fastleo\Fields\Phone;

Phone::make('phone', 'Телефон')->required(),
```

Шаблон лежит в `resources/views/fastleo/fields/phone.blade.php`. Если нужно поменять только вид ячейки в списке — хватит `formatUsing()` или `listView()` без нового класса.

Подробнее — [Своё поле](../fields/custom.md).

## Сохранение и особые разделы

### Как открыть форму создания с уже заполненными полями?

Передайте значения в адрес формы параметром `prefill`. Так делают кнопку «Добавить машину этому клиенту».

```php
// например, в своём действии или шаблоне
route('fastleo.resources.create', [
    'resource' => 'cars',
    'prefill' => ['client_id' => $client->id, 'title' => 'Новая машина'],
]);
```

Заполняются простые поля: текст, число, дата, список, `BelongsTo`. Значение, которое не прошло бы проверку поля, пропускается. Кнопка «Добавить» в [связанной таблице](list.md#как-показать-связанные-записи-на-странице-записи) подставляет родителя сама.

Подробнее — [Форма создания из ссылки](../layout.md#форма-создания-заполненная-из-ссылки).

### Как сделать раздел только для просмотра?

Задайте `$readonly = true` — пропадут «Создать», «Изменить», «Удалить» и «Импорт». Подходит для заявок с сайта, журналов, заказов из внешней системы.

```php
// app/Fastleo/OrderResource.php
protected static bool $readonly = true;
```

Запретить только удаление или только часть записей — Policy модели или метод `can()`: [FAQ: права](access.md).

Подробнее — [Только просмотр](../resources.md#только-просмотр).

### Как включить корзину вместо удаления навсегда?

Добавьте модели `SoftDeletes` и колонку `deleted_at` — в разделе появится вкладка «Корзина» с восстановлением и удалением навсегда.

```php
// миграция
Schema::table('products', function (Blueprint $table) {
    $table->softDeletes();
});
```

```php
// app/Models/Product.php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;
}
```

Кто может восстанавливать и удалять навсегда — методы `restore` и `forceDelete` Policy.

Подробнее — [Корзина](../resources.md#корзина).

### Как выполнить свой код при сохранении записи?

Повесьте observer на модель. Админка пишет через Eloquent по одной записи, поэтому observer срабатывает в форме, импорте, действиях и правке в ячейке.

```php
// app/Observers/ProductObserver.php
namespace App\Observers;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

final class ProductObserver
{
    public function saving(Product $product): bool
    {
        // false отменяет сохранение — админка покажет «Сайт отменил сохранение»
        return ! ($product->is_active && $product->price === null);
    }

    public function saved(Product $product): void
    {
        Cache::forget('catalog');
    }
}
```

```php
// app/Models/Product.php
use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy(ProductObserver::class)]
class Product extends Model {}
```

Понятную ошибку под полем лучше дать правилом поля (`rules()`), а observer оставить для побочных действий: кеш, письма, пересчёт.

Подробнее — [События модели](../resources.md#события-модели).

### Как показать одну модель двумя разделами («Новости» и «Акции»)?

Создайте второй ресурс на ту же модель: свой `$slug`, выборка в `query()` и скрытое поле, которое ставит тип при создании.

```bash
php artisan fastleo:make-resource Promo --model="App\Models\Article"
```

```php
// app/Fastleo/PromoResource.php
namespace App\Fastleo;

use App\Models\Article;
use Fastleo\Fields\Hidden;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;

final class PromoResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'promos';   // у ArticleResource уже articles

    protected static ?string $label = 'Акции';

    protected static ?string $singular = 'Акция';

    public function query(Builder $query): Builder
    {
        return $query->where('type', 'promo');
    }

    public function fields(): array
    {
        return [
            Hidden::make('type')->default('promo')->rules(['in:promo']),
            Text::make('title', 'Заголовок')->required()->searchable(),
        ];
    }
}
```

В первом ресурсе — то же с `'news'`. Запись чужого типа в разделе не откроется (404). Колонка `type` должна быть в `$fillable`.

Подробнее — [query()](../resources.md#query).
