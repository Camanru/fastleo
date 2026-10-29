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

**В списке:** текст как есть.

Варианты `Text` с другой клавиатурой и проверкой: [Email](email.md), [Url](url.md), [Tel](tel.md).
