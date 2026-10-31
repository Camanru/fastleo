# Number

Число: целое или дробное.

```php
use Fastleo\Fields\Number;

Number::make('quantity', 'Количество')->integer()->min(1);
Number::make('price', 'Цена, ₽')->min(0)->step(0.01);
```

**Колонка:** целое — `$table->integer('quantity')` (или `unsignedInteger`); дробное — `$table->decimal('price', 10, 2)->nullable()`.

**Проверка:** число (`numeric`); с `integer()` — целое; `min()`/`max()` — границы.

**В списке:** число как оно лежит в базе (`1500.00`); свой вид — через `formatUsing()`, пример ниже. В Excel/CSV выгружается ячейкой-числом.

## Хранение

Целое (с `integer()`) сохраняется как `int`. Дробное — строкой как введено, поэтому точность колонки `decimal` не теряется.

Красивый вид в списке — `formatUsing()`:

```php
Number::make('price', 'Цена')->formatUsing(fn ($value) => $value === null ? null : number_format((float) $value, 2, ',', ' ').' ₽');
```

| Метод | Что делает |
|---|---|
| `integer()` | Только целые числа; значение сохраняется как `int` |
| `min(0)` | Наименьшее значение |
| `max(100)` | Наибольшее значение |
| `step(0.01)` | Шаг стрелок в поле (по умолчанию 1 для целых, любой для дробных) |

Общие методы — [README](README.md#общие-методы).

← [Все поля](README.md)
