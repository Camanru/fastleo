# Password

Пароль: в модель пишется хеш, поле в форме всегда пустое; `required()` требует пароль только при создании записи.

```php
use Fastleo\Fields\Password;

Password::make('password', 'Пароль')->required()->confirmed();
```

**Колонка:** `$table->string('password')`. Каст `'password' => 'hashed'` в модели не мешает: уже готовый хеш он не хеширует повторно.

**Проверка:** строка и требования сайта `Password::defaults()` (по умолчанию — не короче 8 символов); с `confirmed()` — значения двух полей совпадают. Свои требования задаются в `AppServiceProvider`:

```php
use Illuminate\Validation\Rules\Password;

Password::defaults(fn () => Password::min(12)->letters()->numbers());
```

**В списке:** не показывается. На странице просмотра и в выгрузке Excel/CSV поля тоже нет.

## Поведение

В модель пишется хеш `Hash::make()`; сам хеш никогда не попадает на страницу.

- При создании `required()` требует пароль.
- При редактировании пустое поле оставляет старый пароль (в поле подсказка «Оставьте пустым, чтобы не менять»). `required()` здесь не требует ввода.
- Пробелы по краям — часть пароля, сам пакет их не обрезает (подробности ниже).

**Пробелы по краям.** Обрезать их может middleware `TrimStrings` сайта:

- у поля с именем `password` пробелы сохраняются — это имя `TrimStrings` Laravel пропускает (так же `password_confirmation` и `current_password`);
- у поля с любым другим именем (`password_new`, `pin`) пробелы по краям уберёт `TrimStrings`.

| Метод | Что делает |
|---|---|
| `confirmed()` | Второе поле «Повторите пароль» (`{имя}_confirmation`, для `password` — `password_confirmation`), значения должны совпасть |

Общие методы — [README](README.md#общие-методы).

Внутри вложенных блоков ([HasMany](has-many.md), [HasOne](has-one.md)) поле Password не работает — ошибка описания.

← [Все поля](README.md)
