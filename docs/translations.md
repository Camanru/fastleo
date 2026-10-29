# Многоязычные поля

Если сайт на нескольких языках, текстовое поле может хранить значение для каждого языка. Над таким полем — вкладки языков (`RU | EN`), каждая со своим вводом; вкладка с ошибкой отмечена красной точкой и открывается сама.

```php
// config/fastleo.php
'locales' => ['ru', 'en'],
```

```php
Text::make('title', 'Заголовок')->translatable()->required()->searchable()->sortable(),
RichText::make('body', 'Текст')->translatable(),
```

`translatable()` работает у `Text`, `Textarea`, `Markdown`, `Code` и `RichText`. У остальных полей (`Email`, `Url`, `Slug`, `Select`…) внутри блоков (`HasMany`, `HasOne`, `Repeater`, `Blocks`) и в мини-форме [действия](actions.md) — понятная ошибка описания.

## Хранение

Колонка `json`, значение — объект «язык → текст»:

```json
{"ru": "О клинике", "en": "About us"}
```

Пустые языки не сохраняются. Формат совпадает со [spatie/laravel-translatable](https://github.com/spatie/laravel-translatable), поэтому подходят оба варианта модели:

**С пакетом spatie** — fastleo читает и пишет переводы через него (`getTranslations()`, `replaceTranslations()`), на сайте работает `$page->title` (текст текущего языка):

```php
use Spatie\Translatable\HasTranslations;

class Page extends Model
{
    use HasTranslations;

    public array $translatable = ['title', 'body'];
}
```

**Без пакета** — нужен каст `array` (без него сохранение остановится с понятной ошибкой), на сайте — `$page->title[app()->getLocale()] ?? $page->title['ru']`:

```php
protected function casts(): array
{
    return ['title' => 'array', 'body' => 'array'];
}
```

Перевод обычной колонки: сначала превратите старые строки в JSON, потом смените тип колонки миграцией. Поиск и сортировка списка читают колонку как JSON — строка не в JSON (или колонка `text` в PostgreSQL) даст ошибку базы.

```php
// Один раз, до смены типа; в PostgreSQL смена типа — DB::statement('alter table pages alter column title type json using title::json').
DB::table('pages')->whereNotNull('title')->orderBy('id')->each(fn (object $page) => DB::table('pages')
    ->where('id', $page->id)
    ->update(['title' => json_encode(['ru' => $page->title], JSON_UNESCAPED_UNICODE)]));

Schema::table('pages', fn (Blueprint $table) => $table->json('title')->change());
```

## Языки

- Список — `fastleo.locales` ([configuration.md](configuration.md#языки-содержимого)); `null` — один язык сайта (`app.locale`), вкладок нет, значение всё равно хранится объектом `{"ru": "…"}`.
- Первый язык — **основной**: `required()` требует только его, остальные можно не заполнять. Правила поля (`maxLength`, `rules()`) действуют для каждого языка.
- Текст ошибки называет язык: «Заполните поле «Заголовок (RU)».»

## Список, поиск, сортировка

- В списке и на просмотре — текст основного языка (если его нет — первого заполненного).
- `searchable()` ищет во всех языках.
- `sortable()` сортирует по основному языку.
- [Slug](fields/slug.md) с `from('title')` строится из основного языка.

## Excel и CSV

Многоязычное поле выгружается столбцом на язык: «Заголовок (RU)», «Заголовок (EN)». При загрузке столбец языка — подпись с языком («Заголовок (EN)») или имя с точкой (`title.en`); столбец без языка («Заголовок») — основной язык. Языки без столбца в файле у существующей записи не меняются, пустая ячейка убирает перевод. Подробно — [import-export.md](import-export.md).
