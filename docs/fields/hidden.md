# Hidden

Скрытое значение формы: не видно администратору, но отправляется и записывается.

```php
use Fastleo\Fields\Hidden;

Hidden::make('type')->default('news')->rules(['in:news']);
```

**Колонка:** любая — та, в которую нужно записать значение (`$table->string('type')`).

**Проверка:** значение — не массив (подделанный запрос `type[x]=y` отклоняется). Остальные правила задавайте сами через `rules()` — см. «Безопасность» ниже.

**В списке:** не показывается; на странице просмотра — тоже.

**Обычное применение** — задать значение при создании, когда раздел показывает часть таблицы через `query()` ([resources.md](../resources.md#query)):

```php
public function query(Builder $query): Builder
{
    return $query->where('type', 'news');
}

public function fields(): array
{
    return [
        Hidden::make('type')->default('news')->rules(['in:news']),
        Text::make('title', 'Заголовок')->required(),
    ];
}
```

**Безопасность.** Значение скрытого поля можно подменить запросом — не используйте его для того, что администратор менять не должен. Всегда задавайте правило: `Hidden::make('type')->default('news')->rules(['in:news'])`, или заполняйте колонку в observer модели.

Общие методы — [README](README.md#общие-методы).

Не путать с методом `hidden()` любого поля — он убирает поле из формы совсем.

← [Все поля](README.md)
