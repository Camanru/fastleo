# Конструктор страниц (Blocks)

Поле `Blocks` собирает страницу из блоков разных типов: текст, картинка, галерея, цитата, кнопка… Админ добавляет блоки из меню «Добавить блок», заполняет, сворачивает, перетаскивает и удаляет. Сайт выводит блоки по порядку своими шаблонами.

## Поле

```php
use Fastleo\Fields\Block;
use Fastleo\Fields\Blocks;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\Image;
use Fastleo\Fields\RichText;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;

Blocks::make('content', 'Содержимое')->blocks([
    Block::make('text', 'Текст')->icon('text')->fields([
        RichText::make('body', 'Текст')->required(),
    ]),
    Block::make('image', 'Картинка')->icon('image')->fields([
        Image::make('src', 'Картинка')->directory('pages')->variants('card')->required(),
        Text::make('caption', 'Подпись'),
    ]),
    Block::make('gallery', 'Галерея')->icon('images')->fields([
        Gallery::make('photos', 'Фотографии')->directory('pages')->variants('card'),
    ]),
    Block::make('quote', 'Цитата')->icon('quote')->fields([
        Textarea::make('text', 'Цитата')->required(),
        Text::make('author', 'Автор'),
    ]),
]),
```

**Колонка:** `$table->json('content')->nullable()` и **обязательно** каст в модели `'content' => 'array'`. Без каста сохранение остановится с понятной ошибкой.

**Хранение** — список блоков в порядке формы:

```json
[
    {"type": "text", "data": {"body": "<p>Клиника работает с 2005 года.</p>"}},
    {"type": "quote", "data": {"text": "Лучшие врачи города.", "author": "Пациент"}}
]
```

| Метод | Что делает |
|---|---|
| `blocks([...])` | Типы блоков (`Block::make()`) |
| `min(1)` | Не меньше стольких блоков (с `min` ≥ 1 поле обязательное) |
| `max(30)` | Не больше стольких блоков (по умолчанию 100) |

**Тип блока** — `Block::make('имя', 'Подпись')`:

| Метод | Что делает |
|---|---|
| `icon('image')` | [Иконка Lucide](https://lucide.dev/icons) в меню и заголовке блока |
| `fields([...])` | Поля и раскладка (`Grid`, `Section`) блока |

Имя типа — латиница в нижнем регистре, цифры и `_`, первой — буква (`text`, `photo_gallery`); оно же — имя шаблона на сайте. Повтор имени — ошибка описания.

**В блоке работают** простые поля (текст, числа, даты, списки, галочки, Tags, Code, Markdown, Color), файлы (`Image`, `File`, `Gallery`) и `RichText`, а также `visibleWhen()` по полю того же блока. Не работают: связи, `HasMany`/`HasOne`, другие JSON-поля (`Repeater`, `Blocks`, `KeyValue`), `Slug`, `Password`, `dependsOn()`, `hideOnCreate()`/`hideOnEdit()`/`hidden()`, `readonly()`/`disabled()` (блок сохраняется заново из формы — скрытое значение стёрлось бы), `translatable()`.

**Поведение и проверка.**

- Поля блока проверяются своими правилами («Заполните поле «Цитата».» под полем блока); заголовок блока с ошибкой — красный.
- Тип не из списка — ошибка. В блок попадают только поля его типа — лишние ключи из запроса отбрасываются.
- HTML полей `RichText` чистится, как в обычном поле ([rich-text.md](fields/rich-text.md)); для новых картинок `Image`/`Gallery` с `variants()` создаются варианты.
- Блок без полей (например, «Разделитель») — допустим.

**В списке** поле не показывается; на странице просмотра — блоки по порядку с подписями. В Excel/CSV не выгружается.

## Вывод на сайте

```blade
{{-- resources/views/pages/show.blade.php --}}
<h1>{{ $page->title }}</h1>
<x-fastleo::blocks :blocks="$page->content" />
```

Каждый блок выводится шаблоном сайта `resources/views/blocks/{тип}.blade.php`. Шаблон получает поля блока переменными, а также `$block` (все поля массивом) и `$type`:

```blade
{{-- resources/views/blocks/text.blade.php --}}
<div class="prose">{!! $body ?? '' !!}</div>

{{-- resources/views/blocks/image.blade.php --}}
<figure>
    <x-fastleo::picture :src="$src ?? null" preset="card" :alt="$caption ?? ''" />
    @if (! empty($caption))<figcaption>{{ $caption }}</figcaption>@endif
</figure>

{{-- resources/views/blocks/quote.blade.php --}}
<blockquote>{{ $text ?? '' }} @if (! empty($author)) — {{ $author }} @endif</blockquote>
```

- `{!! $body !!}` безопасен только для `RichText`: его HTML очищен при сохранении. Остальные поля выводите через `{{ }}`.
- Пишите `?? ''`/`?? []`: в блоках, сохранённых до добавления нового поля, его ещё нет.
- Блок типа без шаблона, с неверным именем типа или испорченными данными пропускается без ошибки — так старый тип, который убрали из ресурса, не ломает страницу.
- Имя типа — до 50 символов, не `type`. Не называйте подполя `type`, `block`, `__env`, `slot`, `errors`: эти имена перекрываются переменными шаблона. В шаблон блока попадают и служебные переменные (`$data`, `$view`, `$item`, `$prefix`), не называйте так поля.
- Блоки типа, которого больше нет в `blocks()`, пропадают при следующем сохранении записи в админке: перед удалением типа перенесите их данные.
- Свои папки шаблонов — `prefix`: `<x-fastleo::blocks :blocks="$page->content" prefix="landing.blocks" />` → `resources/views/landing/blocks/{тип}.blade.php`.
