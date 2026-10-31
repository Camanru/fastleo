# Email

Адрес электронной почты.

```php
use Fastleo\Fields\Email;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

Email::make('email', 'Email')->required()->searchable();

// Уникальный адрес: при редактировании своя запись не мешает
Email::make('email')->rules(fn (?Model $record) => [Rule::unique('doctors', 'email')->ignore($record)]);
```

**Колонка:** `$table->string('email')`.

**Проверка:** строка до 255 символов в формате email. Уникальность сама не проверяется — добавьте правило, как во втором примере.

**В списке:** адрес как есть.

Вариант [Text](text.md): на телефоне открывает клавиатуру с `@`.

| Метод | Что делает |
|---|---|
| `maxLength(100)` | Наибольшая длина (по умолчанию 255), как у [Text](text.md) |

Общие методы — [README](README.md#общие-методы).

← [Все поля](README.md)
