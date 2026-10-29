# Password

Пароль. Поле в форме всегда пустое — хеш никогда не попадает на страницу. В модель пишется хеш `Hash::make()`.

```php
use Fastleo\Fields\Password;

Password::make('password', 'Пароль')->required()->confirmed();
```

**Колонка:** `$table->string('password')`. Каст `'password' => 'hashed'` в модели не мешает: уже готовый хеш он не хеширует повторно.

**Поведение:**

- при создании `required()` требует пароль;
- при редактировании пустое поле оставляет старый пароль (в поле подсказка «Оставьте пустым, чтобы не менять»);
- пробелы по краям — часть пароля и не обрезаются, только если поле называется `password` (так же `password_confirmation` и `current_password`): эти имена `TrimStrings` Laravel пропускает. У поля с любым другим именем (`password_new`, `pin`) пробелы по краям уберёт `TrimStrings` сайта;
- в списке и на странице просмотра поля нет.

**Проверка:** строка и требования сайта `Password::defaults()` (по умолчанию — не короче 8 символов). Свои требования задаются в `AppServiceProvider`:

```php
use Illuminate\Validation\Rules\Password;

Password::defaults(fn () => Password::min(12)->letters()->numbers());
```

| Метод | Что делает |
|---|---|
| `confirmed()` | Второе поле «Повторите пароль» (`password_confirmation`), значения должны совпасть |
| `required()` | Обязателен только при создании записи |

Внутри вложенных блоков ([HasMany](has-many.md), [HasOne](has-one.md)) поле Password не работает — ошибка описания.
