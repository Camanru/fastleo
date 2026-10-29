# Email

Адрес электронной почты. Вариант [Text](text.md): на телефоне открывает клавиатуру с `@`.

```php
use Fastleo\Fields\Email;

Email::make('email', 'Email')->required()->searchable();
```

**Колонка:** `$table->string('email')`.

**Проверка:** строка до 255 символов в формате email. Для уникальности добавьте правило:

```php
Email::make('email')->rules(fn (?Model $record) => [Rule::unique('doctors', 'email')->ignore($record)]);
```
