# Markdown

Текст в разметке Markdown с вкладками «Текст» и «Просмотр».

```php
use Fastleo\Fields\Markdown;

Markdown::make('body', 'Текст статьи')->rows(15);
```

**Колонка:** `$table->text('body')->nullable()` (для длинных — `longText`).

**Поведение:** хранится исходный Markdown. Просмотр строит сервер: HTML внутри текста экранируется, ссылки `javascript:` убираются. На странице просмотра записи — готовый HTML.

На сайте выводите с теми же настройками безопасности:

```blade
{!! \Fastleo\Fields\Markdown::render($article->body) !!}
```

(`null` и пустая строка дают пустой результат; то же вручную — `Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false])`).

| Метод | Что делает |
|---|---|
| `rows(15)` | Высота поля в строках (по умолчанию 12) |
| `maxLength(20000)` | Наибольшая длина |

**В списке:** скрыто; с `showInList()` — первые 100 символов исходного текста.
