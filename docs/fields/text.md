# Text

Однострочный текст.

```php
use Fastleo\Fields\Text;

Text::make('title', 'Заголовок')->required()->searchable()->sortable();
Text::make('code', 'Код')->maxLength(20)->placeholder('например, news');
```

**Колонка:** `$table->string('title')` (для `maxLength(20)` — `$table->string('code', 20)`).

**Проверка:** строка не длиннее 255 символов.

| Метод | Что делает |
|---|---|
| `maxLength(20)` | Наибольшая длина (по умолчанию 255, как у `$table->string()`); браузер не даст ввести больше |

Длину колонки пакет сам не читает (форма не должна спрашивать базу при каждом открытии): если колонка шире 255 (`$table->string('title', 500)`), задайте `maxLength(500)` — иначе поле не пропустит длинный текст. `fastleo:doctor` сообщает о таких расхождениях в обе стороны, `fastleo:make-resource --generate` берёт длину из колонки.

**В списке:** текст как есть.

Варианты `Text` с другой клавиатурой и проверкой: [Email](email.md), [Url](url.md), [Tel](tel.md).
