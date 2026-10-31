# HasOne

Одна вложенная запись в форме родителя: «Баннер», «Реквизиты», «Карточка на главной».

```php
use Fastleo\Fields\HasOne;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Fields\Url;

HasOne::make('banner', 'Баннер')->fields([
    Text::make('title', 'Текст баннера'),
    Url::make('link', 'Ссылка'),
    Toggle::make('is_active', 'Показывать'),
]);
```

Имя поля — **метод связи** `HasOne` модели.

**Колонка:** своей колонки нет — вложенная запись лежит в своей таблице с уникальным внешним ключом на родителя:

```bash
php artisan make:model ArticleBanner -m           # модель блока и миграция таблицы article_banners
```

```php
// Миграция
Schema::create('article_banners', function (Blueprint $table) {
    $table->id();
    $table->foreignId('article_id')->unique()->constrained()->cascadeOnDelete();
    $table->string('title')->nullable();
    $table->string('link')->nullable();
    $table->boolean('is_active')->default(false);
    $table->timestamps();
});

// Модель родителя
public function banner(): HasOne
{
    return $this->hasOne(ArticleBanner::class);
}
```

Поля блока — в `$fillable` модели блока.

**Проверка:** правила полей блока; ошибки — под полями блока (`banner.title`).

**В списке:** скрыто. На странице просмотра — «поле — значение».

**Поведение при сохранении:** если записи ещё нет, она создаётся, когда заполнено хотя бы одно поле; существующая обновляется.

- Галочка, ползунок или значение `default()`, которые админ не трогал, не считаются заполненными.
- Удалением запись не управляется.

**Ограничения на поля** — как у [HasMany](has-many.md) (что можно и нельзя внутри блока). Подполя с `visibleWhen()` должны зависеть от поля того же блока, которое доступно всем администраторам (без `readonly()`, `disabled()`, `hidden()`); иначе — ошибка описания.

| Метод | Что делает |
|---|---|
| `fields([...])` | Поля и раскладка блока (`Grid`, `Section`) |

Общие методы — [README](README.md#общие-методы).

Несколько вложенных записей — [HasMany](has-many.md).

← [Все поля](README.md)
