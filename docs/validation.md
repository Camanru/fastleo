# Проверка значений

Значения формы проверяются правилами [валидации Laravel](https://laravel.com/docs/13.x/validation). Правила собираются из полей, видимых в форме.

Пустое поле из формы приходит как `null` (пробелы по краям обрезаются) и записывается в колонку как `null`. Если колонка не допускает NULL или «нет значения» на сайте хранится нулём или пустой строкой, задайте полю `emptyValue(0)` / `emptyValue('')` — правила проверки от этого не меняются, `required()` по-прежнему требует ввод ([пустое значение](fields/README.md#пустое-значение)).

## Порядок правил

1. `required` (если вызван `required()`) или `nullable`;
2. правила типа поля — их поле ставит само;
3. ваши правила из `rules()`.

```php
Text::make('code', 'Код')->required()->maxLength(20)->rules(['alpha_dash']);
// ['required', 'string', 'max:20', 'alpha_dash']
```

| Поле | Правила типа |
|---|---|
| `Text` | `string`, `max:255` (или `maxLength()`) |
| `Textarea` | `string` (+ `max` при `maxLength()`) |
| `Number` | `numeric` или `integer`, `min`, `max` |
| `Checkbox`, `Toggle` | `boolean` |
| `Select` | значение из вариантов; `multiple()` — `array` и каждое значение из вариантов без повторов |
| `Date` | `date_format:Y-m-d` |
| `DateTime` | `date_format:Y-m-d\TH:i` |
| `Time` | `date_format:H:i` |
| `Email` | как `Text` + `email` |
| `Url` | как `Text` + `url:http,https` |
| `Tel` | `string`, `max:32` |
| `Color` | `hex_color` |

## Свои правила

Массивом или замыканием. Замыкание получает редактируемую запись (`null` при создании) — так пишется уникальность:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

Text::make('slug', 'Адрес')
    ->required()
    ->rules(fn (?Model $record) => [Rule::unique('pages', 'slug')->ignore($record)]);

Date::make('ends_on', 'Окончание')->rules(['after_or_equal:starts_on']);
```

**Правило, которому нужны другие поля формы.** Замыкание `rules()` получает только запись. Если проверка зависит от значений других полей (даты брони не пересекаются, пара «склад + запчасть» уникальна), возьмите правило-объект с `DataAwareRule`: оно получает все данные формы и работает одинаково в форме, импорте и MCP.

```bash
php artisan make:rule ApartmentIsFree     # app/Rules/ApartmentIsFree.php
```

```php
namespace App\Rules;

use App\Models\Booking;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

final class ApartmentIsFree implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly ?Model $record) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $busy = Booking::query()
            ->where('apartment_id', $this->data['apartment_id'] ?? null)
            ->whereKeyNot($this->record?->getKey())
            ->where('check_in', '<', $value)
            ->where('check_out', '>', $this->data['check_in'] ?? $value)
            ->exists();

        if ($busy) {
            $fail('Квартира уже занята на эти даты.');
        }
    }
}
```

В ресурсе:

```php
use App\Rules\ApartmentIsFree;
use Fastleo\Fields\Date;
use Illuminate\Database\Eloquent\Model;

Date::make('check_out', 'Выезд')->rules(fn (?Model $record) => ['after:check_in', new ApartmentIsFree($record)]);
```

Не читайте `request()->input(...)` в правиле: при импорте и из MCP запроса формы нет. Правило, зависящее от другого поля, пишите под тем полем, чья ошибка должна появиться в форме. Уникальность внутри блока `HasMany` пишется так же, но `Rule::unique` в блоке не знает `id` соседней вложенной записи: для таких данных ставьте уникальный индекс в базе, ошибка базы показывается над формой ([Ошибки базы данных](#ошибки-базы-данных)).

**Многоязычные поля.** Свои правила `Rule::unique` для поля с `translatable()` сравнивают колонку целиком (весь JSON). Уникальность по одному языку задавайте путём в JSON: `Rule::unique('pages', 'title->ru')`.

Поля, скрытые в форме (`hidden()`, `hideOnCreate()`/`hideOnEdit()`), `readonly()` и `disabled()` не проверяются и не записываются.

## Тексты ошибок

Ошибки показываются под полем по-русски, с подписью поля: «Заполните поле «Заголовок».», «Длина поля «Код» должна быть не больше 20 символов.».

Тексты правил Laravel лежат в пакете. Если в сайте есть `lang/ru/validation.php`, его строки главнее: пакет подставляет свою строку только для правил, которых у сайта нет. Изменить текст для панели и сайта сразу:

```php
// lang/ru/validation.php
return [
    'required' => 'Поле «:attribute» нужно заполнить.',
];
```

Если вы пишете свой `validate()` и берёте тексты пакета, передавайте в `ValidationMessages::all()` правила формы:

```php
use Fastleo\Support\ValidationMessages;

$rules = ['url' => ['required', new MyUrlRule]];

validator($data, $rules, ValidationMessages::all($rules))->validate();
```

Без правил текст правила `url` перехватил бы сообщение любого правила на поле с именем `url` (Laravel подбирает сообщение и по имени поля).

## Ошибки базы данных

Если база отказалась сохранить запись, хотя проверка формы прошла (уникальный индекс без правила `unique`, внешний ключ, `NOT NULL`, слишком длинное значение), над формой появится понятное сообщение, введённые значения сохранятся:

- «Не удалось сохранить: значение в поле «slug» уже есть у другой записи.» (если драйвер не называет колонку или индекс имеет своё имя — «такое значение уже есть у другой записи (адрес, код, email)»; название колонки видно у SQLite, PostgreSQL и у MySQL с именем индекса по умолчанию `таблица_колонка_unique`)
- «Не удалось сохранить или удалить: запись связана с другими записями или указана несуществующая связанная запись.»
- «Не удалось сохранить: не заполнено обязательное поле.»
- «Не удалось сохранить: значение слишком длинное для своего поля.»

Сама ошибка записывается в лог сайта. Лучше не доводить до неё: добавьте в поле правило, которое проверит то же самое заранее, — тогда ошибка покажется под нужным полем.
