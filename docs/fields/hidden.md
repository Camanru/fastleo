# Hidden

Скрытое значение формы: не видно администратору, но отправляется и записывается. В списке и на странице просмотра не показывается.

```php
use Fastleo\Fields\Hidden;

Hidden::make('type')->default('news');
```

Обычное применение — задать значение при создании, когда раздел показывает часть таблицы через `query()` ([resources.md](../resources.md#query)):

```php
public function query(Builder $query): Builder
{
    return $query->where('type', 'news');
}

public function fields(): array
{
    return [
        Hidden::make('type')->default('news'),
        Text::make('title', 'Заголовок')->required(),
    ];
}
```

Значение скрытого поля можно подменить запросом (массив вместо строки пакет отклоняет) — не используйте его для того, что администратор менять не должен. Всегда задавайте правило: `Hidden::make('type')->default('news')->rules(['in:news'])`, или заполняйте колонку в observer модели.

Не путать с методом `hidden()` любого поля — он убирает поле из формы совсем.
