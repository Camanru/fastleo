# FAQ: обычные страницы и контент сайта

Страницы, новости, публикация, адреса, меню, SEO, языки, настройки сайта и вывод текста в шаблонах. Все вопросы — [список](README.md).

## Страницы сайта

### Как сделать раздел «Страницы» для «О нас», «Контакты», «Услуги»?

Создайте таблицу, модель и ресурс, а на сайте — маршрут по адресу страницы. Адрес раздела `pages` занят самой админкой, поэтому задайте ресурсу свой `$slug`.

```php
// database/migrations/2026_11_01_000000_create_pages_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content')->nullable();
            $table->boolean('is_published')->default(true);
            $table->json('seo')->nullable();
            $table->timestamps();
        });
    }
};
```

```php
// app/Models/Page.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $fillable = ['title', 'slug', 'content', 'is_published', 'seo'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'seo' => 'array'];
    }
}
```

```php
// app/Fastleo/PageResource.php
namespace App\Fastleo;

use App\Models\Page;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;

final class PageResource extends Resource
{
    protected static string $model = Page::class;

    protected static ?string $slug = 'site-pages'; // «pages» занят админкой

    protected static ?string $label = 'Страницы';

    protected static ?string $singular = 'Страница';

    protected static ?string $group = 'Контент';

    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required()->searchable()->sortable(),
            Slug::make('slug', 'Адрес')->from('title'),
            RichText::make('content', 'Текст'),
            Toggle::make('is_published', 'Показывать на сайте')->quickToggle(),
            Seo::make('seo'),
        ];
    }

    public function url(Model $record): ?string
    {
        return route('page.show', $record->slug);
    }
}
```

```php
// routes/web.php — последним, чтобы не перекрыть другие адреса
use App\Models\Page;

Route::get('/{slug}', function (string $slug) {
    $page = Page::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();

    return view('page', ['page' => $page]);
})->where('slug', '[a-z0-9-]+')->name('page.show');
```

```blade
{{-- resources/views/page.blade.php --}}
<h1>{{ $page->title }}</h1>
<div class="content">{!! $page->content !!}</div>
```

Проверьте раздел командой `php artisan fastleo:doctor`. Подробнее — [первый раздел](../resources.md#первый-раздел), [свойства ресурса](../resources.md#свойства).

### Как сделать главную страницу из блоков?

Добавьте странице поле `Blocks` и выведите его компонентом `<x-fastleo::blocks>`: каждый тип блока — свой шаблон в `resources/views/blocks`.

```php
// app/Fastleo/PageResource.php
use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Image;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Text;

Blocks::make('blocks', 'Блоки страницы')->blocks([
    Block::make('hero', 'Первый экран')->icon('image')->fields([
        Text::make('title', 'Заголовок')->required(),
        Image::make('image', 'Картинка')->directory('home'),
    ]),
    Block::make('text', 'Текст')->icon('text')->fields([
        RichText::make('body', 'Текст')->required(),
    ]),
]),
```

1. Миграция: `$table->json('blocks')->nullable();`
2. Модель: `'blocks'` в `$fillable` и каст `'blocks' => 'array'`.
3. Шаблоны блоков:

```blade
{{-- resources/views/home.blade.php --}}
<x-fastleo::blocks :blocks="$page->blocks" />

{{-- resources/views/blocks/hero.blade.php --}}
<section class="hero">
    <h1>{{ $title ?? '' }}</h1>
    <x-fastleo::picture :src="$image ?? null" :alt="$title ?? ''" />
</section>

{{-- resources/views/blocks/text.blade.php --}}
<div class="content">{!! $body ?? '' !!}</div>
```

Главную найдите по адресу, например `Page::where('slug', 'glavnaya')->firstOrFail()`. Для лендинга со своими шаблонами блоков передайте папку: `<x-fastleo::blocks :blocks="$page->blocks" prefix="landing.blocks" />` → `resources/views/landing/blocks/{тип}.blade.php`. Подробнее — [конструктор страниц](../blocks.md), [вывод блоков на сайте](../blocks.md#вывод-на-сайте).

### Как сделать редактируемый текст на главной, если это не список записей?

Сделайте страницу настроек «Главная» — она хранит одну запись без таблицы.

```php
// app/Fastleo/HomeSettings.php
namespace App\Fastleo;

use Fastleo\Fields\Image;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Text;

final class HomeSettings extends \Fastleo\Settings
{
    protected static ?string $label = 'Главная';

    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            RichText::make('intro', 'Вступление'),
            Image::make('banner', 'Баннер')->directory('home'),
        ];
    }
}
```

```blade
{{-- resources/views/home.blade.php --}}
<h1>{{ fastleo_setting('home.title') }}</h1>
<div class="content">{!! fastleo_setting('home.intro') !!}</div>
<x-fastleo::picture :src="fastleo_setting('home.banner')" alt="" />
```

Заготовка класса — `php artisan fastleo:make-settings Home`. Подробнее — [настройки сайта](../settings.md).

## Новости и статьи

### Как сделать раздел «Новости» с анонсом, текстом и обложкой?

Миграция, модель с кастами и ресурс; дата публикации и галочка — в боковой колонке формы.

```php
// database/migrations/2026_11_01_000100_create_news_table.php
Schema::create('news', function (Blueprint $table): void {
    $table->id();
    $table->string('title');
    $table->string('slug')->unique();
    $table->text('excerpt')->nullable();
    $table->longText('content')->nullable();
    $table->string('cover')->nullable();
    $table->boolean('is_published')->default(false);
    $table->dateTime('published_at')->nullable();
    $table->json('seo')->nullable();
    $table->timestamps();
});
```

```php
// app/Models/News.php
namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    protected $fillable = ['title', 'slug', 'excerpt', 'content', 'cover', 'is_published', 'published_at', 'seo'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'published_at' => 'datetime', 'seo' => 'array'];
    }

    /** Видимые посетителям: галочка стоит и дата публикации наступила. */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true)
            ->where(fn (Builder $query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }
}
```

```php
// app/Fastleo/NewsResource.php
namespace App\Fastleo;

use App\Models\News;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Image;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Seo;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Layout\Aside;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;

final class NewsResource extends Resource
{
    protected static string $model = News::class;

    protected static ?string $label = 'Новости';

    protected static ?string $singular = 'Новость';

    protected static string $icon = 'newspaper';

    protected static ?string $group = 'Контент';

    protected static ?array $defaultOrder = ['published_at', 'desc'];

    public function fields(): array
    {
        return [
            Tabs::make([
                Tab::make('Новость', [
                    Text::make('title', 'Заголовок')->required()->searchable()->sortable(),
                    Slug::make('slug', 'Адрес')->from('title')->hideInList(),
                    Textarea::make('excerpt', 'Анонс')->rows(3),
                    RichText::make('content', 'Текст'),
                    Image::make('cover', 'Обложка')->directory('news')->crop('16:9'),
                ]),
                Tab::make('SEO', [Seo::make('seo')->directory('news')]),
            ]),
            Aside::make([
                Toggle::make('is_published', 'Опубликовано')->quickToggle(),
                DateTime::make('published_at', 'Дата публикации')->default(fn () => now())->sortable(),
            ]),
        ];
    }

    public function url(Model $record): ?string
    {
        return route('news.show', $record->slug);
    }
}
```

```php
// routes/web.php
use App\Http\Controllers\NewsController;

Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/news/{slug}', [NewsController::class, 'show'])->name('news.show');
```

```php
// app/Http/Controllers/NewsController.php
namespace App\Http\Controllers;

use App\Models\News;
use Illuminate\View\View;

class NewsController extends Controller
{
    public function index(): View
    {
        return view('news.index', ['news' => News::query()->published()->latest('published_at')->paginate(12)]);
    }

    public function show(string $slug): View
    {
        return view('news.show', ['news' => News::query()->published()->where('slug', $slug)->firstOrFail()]);
    }
}
```

Подробнее — [раскладка формы](../layout.md), [Slug](../fields/slug.md), [Image](../fields/image.md), [RichText](../fields/rich-text.md).

### Как сделать рубрики у новостей?

Заведите отдельный раздел «Рубрики» и поле `BelongsTo` у новости.

```php
// app/Fastleo/NewsResource.php
use Fastleo\Fields\BelongsTo;

BelongsTo::make('category_id', 'Рубрика'),
```

Миграция рубрик должна выполняться раньше миграции новостей; у модели `News` нужен метод `category()`. Как описать связь целиком — [выбор категории](crud.md#как-выбрать-категорию-у-товара-belongsto), [BelongsTo](../fields/belongs-to.md). Адрес рубрики на сайте — так же, как у новости: `Slug` в разделе рубрик и маршрут `/news/category/{slug}`.

### Как показать «Новости» и «Статьи» из одной таблицы двумя разделами?

Сделайте два ресурса одной модели и ограничьте каждый методом `query()`; тип новой записи задайте скрытым полем.

```php
// app/Fastleo/ArticleResource.php
use Fastleo\Fields\Hidden;
use Illuminate\Database\Eloquent\Builder;

protected static ?string $slug = 'articles';

public function query(Builder $query): Builder
{
    return $query->where('type', 'article');
}

public function fields(): array
{
    return [
        Hidden::make('type')->default('article')->rules(['in:article']),
        // остальные поля
    ];
}
```

Во втором ресурсе — `'news'` и `protected static ?string $slug = 'news';`. Миграция: `$table->string('type')->index();`, `type` — в `$fillable`. Подробнее — [`query()`](../resources.md#query).

## Публикация и черновики

### Как скрыть запись с сайта галочкой?

Добавьте `Toggle` и выбирайте на сайте только отмеченные записи. `quickToggle()` даёт переключать прямо в списке, `SetToggle` — сразу у многих записей.

```php
// app/Fastleo/NewsResource.php
use Fastleo\Actions\SetToggle;
use Fastleo\Fields\Toggle;

Toggle::make('is_published', 'Опубликовано')->quickToggle(),

public function actions(): array
{
    return [
        SetToggle::make('is_published', 'Опубликовать', true),
        SetToggle::make('is_published', 'Снять с публикации', false),
    ];
}
```

Миграция — `$table->boolean('is_published')->default(false);`, каст — `'is_published' => 'boolean'`, на сайте — `News::where('is_published', true)` или scope из вопроса [про новости](#как-сделать-раздел-новости-с-анонсом-текстом-и-обложкой). Подробнее — [Toggle](../fields/toggle.md#быстрый-переключатель-в-списке), [«Показать» / «Скрыть»](../actions.md#готовое-действие-показать--скрыть).

### Как опубликовать новость в назначенный день и час?

Добавьте поле `DateTime` и показывайте на сайте только записи, чья дата уже наступила. Запись «появится» сама в нужный момент — задача по расписанию не нужна.

```php
// app/Fastleo/NewsResource.php
use Fastleo\Fields\DateTime;

DateTime::make('published_at', 'Дата публикации')->default(fn () => now())->sortable(),
```

```php
// там, где выбираете новости для сайта
News::query()
    ->where('is_published', true)
    ->where('published_at', '<=', now())
    ->latest('published_at')
    ->get();
```

Админ вводит время в поясе админки (`fastleo.timezone`), в базе оно хранится в поясе сайта — сравнение с `now()` верное. Подробнее — [DateTime](../fields/date-time.md).

### Как сделать черновики?

Отдельного режима черновиков в пакете нет: черновик — это запись со снятой галочкой «Опубликовано». Задайте `default(false)` в миграции, чтобы новая запись не уходила на сайт сразу, и добавьте фильтр в список.

```php
// app/Fastleo/NewsResource.php
use Fastleo\Filters\BooleanFilter;

public function filters(): array
{
    return [
        BooleanFilter::make('is_published', 'Опубликовано'),
    ];
}
```

Если нужны статусы («Черновик», «На проверке», «Опубликовано») — поле `Enum` с `->badges()`: [Enum](../fields/enum.md), [фильтры](../list.md#фильтры).

### Как добавить кнопку «Открыть на сайте»?

Опишите в ресурсе метод `url()` — кнопка появится в форме и на странице просмотра записи.

```php
// app/Fastleo/NewsResource.php
use Illuminate\Database\Eloquent\Model;

public function url(Model $record): ?string
{
    return route('news.show', $record->slug);
}
```

Верните `null`, если у записи нет адреса (например, адрес не заполнен). Подробнее — [`url()`](../resources.md#url).

### Как посмотреть неопубликованную запись на сайте до публикации?

Пропускайте на страницу записи вошедшего администратора админки, даже если запись скрыта. Кнопка «Открыть на сайте» тогда работает и для черновиков.

```php
// app/Http/Controllers/NewsController.php
use App\Models\News;
use Fastleo\Fastleo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

public function show(string $slug): View
{
    $isAdmin = Fastleo::guard()->user()?->canAccessFastleo() === true;

    $news = News::query()
        ->when(! $isAdmin, fn (Builder $query) => $query->published())
        ->where('slug', $slug)
        ->firstOrFail();

    return view('news.show', ['news' => $news, 'isPreview' => ! $news->is_published]);
}
```

```blade
@if ($isPreview)
    <div class="notice">Черновик: посетители эту страницу не видят</div>
@endif
```

Работает, когда админка и сайт на одном домене (общая сессия). Если админка вынесена на отдельный домен, задайте общий домен cookie (`SESSION_DOMAIN=.site.ru`). Подробнее — [адрес админки](../configuration.md#адрес).

## Адреса, порядок и дерево

### Как сделать адрес страницы из заголовка (`/news/novyj-filial`)?

Добавьте поле `Slug` с `from()` — адрес заполнится из заголовка при вводе и не изменится, когда заголовок поправят позже.

```php
// app/Fastleo/NewsResource.php
use Fastleo\Fields\Slug;

Slug::make('slug', 'Адрес')->from('title'),
```

Миграция — `$table->string('slug')->unique();`, на сайте — `News::where('slug', $slug)->firstOrFail()`. Тот же адрес в своём коде (сидер, импорт) — `Slug::slugify('Новый филиал')`. Подробнее — [Slug](../fields/slug.md).

### Как задать порядок страниц или услуг вручную?

Укажите колонку порядка — в списке появятся стрелки и перетаскивание.

```php
// app/Fastleo/ServiceResource.php
protected static ?string $sortColumn = 'sort';
```

Миграция — `$table->unsignedInteger('sort')->default(0);`. В `$fillable` и `fields()` колонку добавлять не нужно. На сайте — `Service::orderBy('sort')->get()`. Подробнее — [ручной порядок](../resources.md#ручной-порядок).

### Как сделать вложенные страницы с адресом `/uslugi/implantaciya`?

Включите дерево в ресурсе, а на сайте разберите адрес по частям.

```php
// app/Fastleo/PageResource.php
use Fastleo\Fields\BelongsTo;
use Illuminate\Database\Eloquent\Model;

protected static ?string $treeColumn = 'parent_id';

protected static ?string $sortColumn = 'sort';

// в fields():
BelongsTo::make('parent_id', 'Родитель')->hideInList(),

public function url(Model $record): ?string
{
    return url($record->path());
}
```

```php
// миграция
$table->foreignId('parent_id')->nullable()->constrained('pages')->nullOnDelete();
$table->unsignedInteger('sort')->default(0);
```

```php
// app/Models/Page.php
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

public function parent(): BelongsTo
{
    return $this->belongsTo(self::class, 'parent_id');
}

public function children(): HasMany
{
    return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
}

/** Адрес от корня: uslugi/implantaciya. */
public function path(): string
{
    return trim(($this->parent?->path() ?? '').'/'.$this->slug, '/');
}
```

```php
// routes/web.php — последним маршрутом
use App\Models\Page;

Route::get('/{path}', function (string $path) {
    $page = null;

    foreach (explode('/', $path) as $slug) {
        $page = Page::query()
            ->where('parent_id', $page?->id)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();
    }

    return view('page', ['page' => $page]);
})->where('path', '[a-z0-9\-/]+')->name('page.show');
```

`parent_id` — в `$fillable`. Подробнее — [дерево](../resources.md#дерево).

### Как вывести меню сайта из раздела «Страницы»?

Добавьте галочку «В меню», выберите отмеченные страницы во view composer и сбрасывайте кеш при сохранении страницы.

```php
// app/Fastleo/PageResource.php
Toggle::make('in_menu', 'Показывать в меню')->quickToggle(),
```

Миграция — `$table->boolean('in_menu')->default(false);`, `in_menu` — в `$fillable`, каст `boolean`. Меню двухуровневое, адреса — как в вопросе [про вложенные страницы](#как-сделать-вложенные-страницы-с-адресом-uslugiimplantaciya).

```php
// app/Providers/AppServiceProvider.php
use App\Models\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;

public function boot(): void
{
    View::composer('partials.menu', function (\Illuminate\View\View $view): void {
        $view->with('menu', Cache::rememberForever('site-menu', fn () => Page::query()
            ->where('in_menu', true)
            ->where('is_published', true)
            ->whereNull('parent_id')
            ->orderBy('sort')
            ->with(['children' => fn ($query) => $query->where('in_menu', true)->where('is_published', true)])
            ->get()));
    });
}
```

```php
// app/Models/Page.php — сбросить меню после любой правки
use Illuminate\Support\Facades\Cache;

protected static function booted(): void
{
    static::saved(fn () => Cache::forget('site-menu'));
    static::deleted(fn () => Cache::forget('site-menu'));
}
```

```blade
{{-- resources/views/partials/menu.blade.php --}}
<nav>
    @foreach ($menu as $item)
        <a href="{{ url($item->slug) }}">{{ $item->title }}</a>
        @foreach ($item->children as $child)
            <a href="{{ url($item->slug.'/'.$child->slug) }}">{{ $child->title }}</a>
        @endforeach
    @endforeach
</nav>
```

Админка сохраняет записи через Eloquent — `saved` срабатывает и на стрелках порядка, и на быстром переключателе. Подробнее — [события модели](../resources.md#события-модели). Меню самой админки — [admin-ui.md](admin-ui.md#как-сгруппировать-пункты-меню-и-задать-их-порядок).

## SEO, карта сайта и robots.txt

### Как добавить к странице заголовок и описание для поисковиков?

Добавьте поле `Seo` на отдельной вкладке, колонку `json` и каст `array`.

```php
// app/Fastleo/NewsResource.php
use Fastleo\Fields\Seo;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;

Tabs::make([
    Tab::make('Новость', [/* поля новости */]),
    Tab::make('SEO', [Seo::make('seo')->directory('news')]),
]),
```

Миграция — `$table->json('seo')->nullable();`, модель — `'seo'` в `$fillable` и `'seo' => 'array'` в `casts()`. Подробнее — [поле Seo](../fields/seo.md).

### Как вывести title, description и Open Graph в шаблоне?

Поставьте `<x-fastleo::seo>` в `<head>` вместо своего `<title>`. Пустые значения поля Seo он заполнит из переданных атрибутов.

```blade
{{-- resources/views/layouts/site.blade.php --}}
<head>
    @hasSection('seo')
        @yield('seo')
    @else
        <x-fastleo::seo />
    @endif
</head>

{{-- resources/views/news/show.blade.php --}}
@extends('layouts.site')

@section('seo')
    <x-fastleo::seo :model="$news" :title="$news->title" :description="$news->content" :image="$news->cover" type="article" />
@endsection
```

На страницах без записи `<x-fastleo::seo title="Контакты" />` выведет заголовок, canonical и Open Graph. Подробнее — [теги на странице](../seo.md#теги-на-странице).

### Как закрыть отдельную страницу от поисковиков?

Отметьте в поле Seo записи галочку «Не показывать в поиске». `<x-fastleo::seo>` выведет `<meta name="robots" content="noindex">`, а запись пропадёт из `sitemap.xml`. Подробнее — [Seo](../fields/seo.md#в-форме).

### Как включить sitemap.xml?

Включите карту в конфиге и опишите в ресурсе `url()` и `sitemap()` — какие записи в неё попадают.

```php
// config/fastleo.php
'sitemap' => [
    'enabled' => true,
    'cache_minutes' => 60,
    'urls' => ['/', '/contacts'], // страницы без раздела
],
```

```php
// app/Fastleo/NewsResource.php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

public function url(Model $record): ?string
{
    return route('news.show', $record->slug);
}

public function sitemap(Builder $query): ?Builder
{
    return $query->published();
}
```

После включения пересоберите кеш маршрутов (`php artisan optimize`), если он есть. Карта обновляется сама при сохранении записи. Подробнее — [карта сайта](../seo.md#карта-сайта).

### Как отдавать robots.txt?

Включите его в конфиге и удалите файл `public/robots.txt` — иначе веб-сервер отдаст файл раньше Laravel.

```php
// config/fastleo.php
'robots' => [
    'enabled' => true,
    'lines' => ['User-agent: *', 'Disallow: /search', 'Disallow: /cart'],
],
```

Строка `Sitemap: https://site.ru/sitemap.xml` добавится сама, если включена карта сайта. Подробнее — [robots.txt](../seo.md#robotstxt).

## Многоязычные страницы

### Как сделать страницы на двух языках?

Перечислите языки в конфиге, пометьте текстовые поля `translatable()` и храните их в колонках `json`.

```php
// config/fastleo.php
'locales' => ['ru', 'en'],
```

```php
// app/Fastleo/PageResource.php
Text::make('title', 'Заголовок')->translatable()->required()->searchable(),
RichText::make('content', 'Текст')->translatable(),
Slug::make('slug', 'Адрес')->from('title'), // адрес один на все языки, строится из первого языка
```

```php
// миграция: $table->json('title'); $table->json('content')->nullable();
// app/Models/Page.php — без пакета spatie/laravel-translatable
protected function casts(): array
{
    return ['title' => 'array', 'content' => 'array'];
}
```

```php
// routes/web.php
Route::get('/{locale}/{slug}', function (string $locale, string $slug) {
    app()->setLocale($locale);

    return view('page', ['page' => Page::where('slug', $slug)->firstOrFail()]);
})->whereIn('locale', ['ru', 'en']);
```

```blade
<h1>{{ $page->title[app()->getLocale()] ?? $page->title['ru'] }}</h1>
{!! $page->content[app()->getLocale()] ?? $page->content['ru'] ?? '' !!}
```

С пакетом spatie/laravel-translatable вместо каста подключите трейт `HasTranslations` — тогда в шаблоне просто `{{ $page->title }}`. Подробнее — [многоязычные поля](../translations.md), перевод старой колонки — [хранение](../translations.md#хранение).

## Настройки сайта

### Как сделать телефон, адрес и соцсети, которые меняет администратор?

Создайте страницу настроек командой `php artisan fastleo:make-settings Contacts` и опишите поля.

```php
// app/Fastleo/ContactsSettings.php
namespace App\Fastleo;

use Fastleo\Fields\Email;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Url;

final class ContactsSettings extends \Fastleo\Settings
{
    protected static ?string $label = 'Контакты';

    protected static string $icon = 'phone';

    public function fields(): array
    {
        return [
            Tel::make('phone', 'Телефон')->default('+7 900 000-00-00'),
            Email::make('email', 'Почта'),
            Text::make('address', 'Адрес'),
            Textarea::make('hours', 'Часы работы'),
            Repeater::make('socials', 'Соцсети')->fields([
                Text::make('title', 'Название')->required(),
                Url::make('url', 'Ссылка')->required(),
            ]),
        ];
    }
}
```

Страница появится в меню админки в группе «Настройки». Подробнее — [страница настроек](../settings.md#страница-настроек).

### Как вывести настройки в шапке и подвале сайта?

Читайте значения функцией `fastleo_setting('страница.поле')` — все настройки берутся одним запросом из кеша, вызывать её можно сколько угодно раз.

```blade
{{-- resources/views/partials/footer.blade.php --}}
@php($phone = fastleo_setting('contacts.phone'))
@if ($phone)
    <a href="tel:{{ preg_replace('/[^+\d]/', '', $phone) }}">{{ $phone }}</a>
@endif
<address>{{ fastleo_setting('contacts.address') }}</address>
<p>{!! nl2br(e(fastleo_setting('contacts.hours', ''))) !!}</p>
@foreach (fastleo_setting('contacts.socials', []) as $social)
    <a href="{{ $social['url'] }}">{{ $social['title'] }}</a>
@endforeach
```

В PHP-коде (письма, контроллеры) — то же: `fastleo_setting('contacts.email', 'info@site.ru')`. Подробнее — [читать настройки на сайте](../settings.md#читать-настройки-на-сайте).

### Как вставлять счётчик Метрики или код в `<head>` из админки?

Добавьте на страницу настроек поле `Code` и выведите его без экранирования. Код не чистится — закройте страницу для всех, кроме доверенных администраторов.

```php
// app/Fastleo/CountersSettings.php
namespace App\Fastleo;

use Fastleo\Fields\Code;
use Illuminate\Contracts\Auth\Authenticatable;

final class CountersSettings extends \Fastleo\Settings
{
    protected static ?string $label = 'Счётчики';

    public function fields(): array
    {
        return [
            Code::make('head', 'Код в <head>')->rows(10),
            Code::make('body', 'Код перед </body>')->rows(10),
        ];
    }

    public function canAccess(?Authenticatable $user): bool
    {
        return $user?->email === 'owner@site.ru';
    }
}
```

```blade
{{-- resources/views/layouts/site.blade.php --}}
<head>
    {!! fastleo_setting('counters.head') !!}
</head>
<body>
    …
    {!! fastleo_setting('counters.body') !!}
</body>
```

Подробнее — [Code](../fields/code.md), [доступ к настройкам](../settings.md#доступ).

### Как закрыть сайт на время работ, оставив админку рабочей?

Включите закрытие в конфиге и поставьте галочку на странице «Закрытие сайта» в админке.

```php
// config/fastleo.php
'closing' => [
    'enabled' => true,
],
```

После правки конфига выполните `php artisan config:cache` (если кеш конфига включён). Посетители увидят страницу с кодом 503, вошедшие администраторы — сайт как обычно. Свой вид страницы — файл `resources/views/vendor/fastleo/site-closed.blade.php` с переменной `$message`. `php artisan down` для этого не подходит: он закрывает и админку. Подробнее — [закрытие сайта](../site-closing.md).

## Текст, Markdown и вставки

### Как вывести текст из визуального редактора на сайте?

Выводите поле `RichText` без экранирования: HTML очищен при сохранении.

```blade
{{-- resources/views/news/show.blade.php --}}
<article class="content">{!! $news->content !!}</article>
```

Оформление (`.content h2`, `.content img`, `.content table`) задайте в CSS сайта. Чтобы в редакторе текст выглядел так же, подключите эти стили в админке:

```php
// config/fastleo.php
'rich_text' => [
    'content_css' => ['/css/app.css'],
],
```

Подробнее — [RichText](../fields/rich-text.md), [настройки редактора](../configuration.md#чистка-html-редактора).

### Почему из текста пропали классы, `div` или цвета?

При сохранении редактор оставляет только структуру текста. Разрешите нужное на весь сайт в `config/fastleo.php`.

```php
// config/fastleo.php
'rich_text' => [
    'tags' => ['div', 'span'],
    'attributes' => ['class'],
    'styles' => true, // оставлять атрибут style целиком (цвета, шрифты)
],
```

Перед первым сохранением старых записей проверьте, что изменится: `php artisan fastleo:doctor --sanitize-diff`. Подробнее — [чистка HTML](../fields/rich-text.md#чистка-html), [свои теги на весь сайт](../fields/rich-text.md#свои-теги-и-атрибуты-на-весь-сайт).

### Как вставить в текст видео с YouTube, Rutube или VK?

Нажмите в редакторе кнопку «Вставить/редактировать мультимедиа».

- **YouTube** — вставьте ссылку на видео во вкладке «Общие»: редактор сам превратит её в плеер.
- **Rutube и VK Видео** — на сайте видео нажмите «Поделиться → Встроить», скопируйте код `<iframe>` и вставьте его во вкладку «Встроить». Ссылку на страницу видео сюда не вставляйте: редактор сделает из неё `<video>`, который чистка HTML не пропускает, и видео пропадёт после сохранения.

Плееры YouTube, Rutube и VK Видео разрешены сразу. Для другого сервиса (например, Vimeo — его ссылку редактор тоже превращает в плеер) добавьте начало адреса его плеера:

```php
// config/fastleo.php
'rich_text' => [
    'iframe_hosts' => ['https://player.vimeo.com/video/'],
],
```

Чтобы видео тянулось по ширине, задайте в CSS сайта `.content iframe { width: 100%; aspect-ratio: 16 / 9; height: auto; }`. Подробнее — [что остаётся в тексте](../fields/rich-text.md#чистка-html).

### Как вставить на страницу карту, виджет или форму стороннего сервиса?

Если это `iframe` — разрешите адрес в `rich_text.iframe_hosts` и вставьте его в RichText (как видео выше). Если это скрипт — добавьте записи поле `Code` и выведите его отдельно от текста.

```php
// app/Fastleo/PageResource.php
use Fastleo\Fields\Code;

Code::make('widget', 'Код виджета')->rows(6)->help('Вставляется на страницу без изменений')->hideInList(),
```

```blade
{{-- resources/views/page.blade.php --}}
<div class="content">{!! $page->content !!}</div>
@if ($page->widget)
    {!! $page->widget !!}
@endif
```

Скрипт из `Code` выполнится у посетителей — давайте это поле только доверенным администраторам (`hidden()` или Policy). Подробнее — [Code](../fields/code.md).

### Как писать текст в Markdown и вывести его на сайте?

Используйте поле `Markdown`, а на сайте — `Markdown::render()`: он экранирует HTML внутри текста и убирает опасные ссылки.

```php
// app/Fastleo/NewsResource.php
use Fastleo\Fields\Markdown;

Markdown::make('body', 'Текст')->rows(15),
```

```blade
{{-- resources/views/news/show.blade.php --}}
<article class="content">{!! \Fastleo\Fields\Markdown::render($news->body) !!}</article>
```

Миграция — `$table->text('body')->nullable();`. Подробнее — [Markdown](../fields/markdown.md#вывод-на-сайте).
