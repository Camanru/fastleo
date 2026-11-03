# Date

Дата без времени, вводится календарём браузера.

```php
use Fastleo\Fields\Date;

Date::make('published_on', 'Дата публикации')->default(fn () => now()->toDateString());
```

**Колонка:** `$table->date('published_on')->nullable()`. **Модель:** каст `'published_on' => 'date:Y-m-d'` (необязательно). Не `'date'`: он записывает в колонку `2026-11-03 00:00:00`, и на SQLite строковое сравнение дат (`date_to > '2026-11-03'`) даёт неверный ответ; `date:Y-m-d` пишет ровно дату.

**Проверка:** формат `ГГГГ-ММ-ДД`.

**В списке:** `08.03.2026`.

Общие методы — [README](README.md#общие-методы).

Дата со временем — [DateTime](date-time.md), только время — [Time](time.md).

← [Все поля](README.md)
