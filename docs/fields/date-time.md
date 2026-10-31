# DateTime

Дата и время (часы и минуты).

```php
use Fastleo\Fields\DateTime;

DateTime::make('starts_at', 'Начало')->required();
DateTime::make('created_at', 'Создано')->onlyInList()->sortable();
DateTime::make('starts_at', 'Начало')->default(fn () => now()); // покажется в поясе админки
```

**Колонка:** `$table->dateTime('starts_at')->nullable()` или `timestamp`. **Модель:** каст `'starts_at' => 'datetime'` (необязательно).

**Проверка:** дата и время из поля браузера (`ГГГГ-ММ-ДДTЧЧ:ММ`).

**В списке:** `08.03.2026 12:30` (в поясе админки).

**Часовой пояс.** В форме и списке время показывается в поясе админки, а в базу пишется в поясе сайта, как и всё остальное в Laravel.

- Пояс админки — `fastleo.timezone` ([настройки](../configuration.md)), по умолчанию пояс сайта `app.timezone`.
- Пример: сайт в `UTC`, админка в `Europe/Moscow` — администратор вводит 12:30, в базе 09:30:00.

Общие методы — [README](README.md#общие-методы).

Только дата — [Date](date.md), только время — [Time](time.md).

← [Все поля](README.md)
