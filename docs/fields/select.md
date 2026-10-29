# Select

Выбор из списка: один вариант или несколько.

```php
use Fastleo\Fields\Select;

// Массив «значение => подпись»
Select::make('status', 'Статус')->options([
    'draft' => 'Черновик',
    'published' => 'Опубликовано',
])->required();

// PHP backed enum (enum X: string или int): подписи из метода label(), если он есть, иначе имена вариантов
Select::make('status', 'Статус')->options(ArticleStatus::class);

// Замыкание — вызывается один раз за запрос (форма, список, проверка)
Select::make('city', 'Город')->options(fn () => City::query()->orderBy('name')->pluck('name', 'id')->all());

// Несколько вариантов
Select::make('days', 'Дни приёма')->options([1 => 'Пн', 2 => 'Вт', 3 => 'Ср'])->multiple();
```

**Колонка:**

- один вариант — `$table->string('status', 20)` (или `integer` для числовых значений);
- несколько — `$table->json('days')->nullable()` и каст `'days' => 'array'` в модели.

Для enum добавьте каст: `'status' => ArticleStatus::class`.

```php
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Published => 'Опубликовано',
        };
    }
}
```

**Проверка:** выбрано значение из списка вариантов (подставить своё значение через запрос нельзя). Для `multiple()` — массив значений из списка без повторов.

| Метод | Что делает |
|---|---|
| `options([...])` | Варианты: массив, класс backed enum или замыкание |
| `multiple()` | Можно выбрать несколько; ничего не выбрано — сохраняется пустой массив |
| `placeholder('Выберите')` | Текст пустого варианта (по умолчанию «—») |
| `dependsOn('brand')` | Зависимый список: замыкание вариантов получает значение поля `brand` ([layout.md](../layout.md#зависимые-списки)) |

**В списке:** подписи выбранных вариантов через запятую. Значение, которого нет среди вариантов, показывается как есть. Поиск (`searchable()`) идёт по сохранённому значению (`published`), а не по подписи.

Кнопки вместо списка — [Radio](radio.md), варианты из enum — [Enum](enum.md). Связь с записью другой таблицы (`category_id` → категория) — поле [BelongsTo](belongs-to.md).
