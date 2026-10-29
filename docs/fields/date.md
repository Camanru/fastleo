# Date

Дата без времени. Вводится календарём браузера.

```php
use Fastleo\Fields\Date;

Date::make('published_on', 'Дата публикации')->default(fn () => now()->toDateString());
```

**Колонка:** `$table->date('published_on')->nullable()`. **Модель:** каст `'published_on' => 'date'` (необязательно).

**Проверка:** формат `ГГГГ-ММ-ДД`.

**В списке:** `08.03.2026`.

Дата со временем — [DateTime](date-time.md), только время — [Time](time.md).
