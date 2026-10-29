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

Имя поля — **метод связи** `HasOne` модели:

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

**Поведение:** если записи ещё нет, она создаётся, когда заполнено хотя бы одно поле (галочка, ползунок или значение `default()`, которые админ не трогал, не считаются заполненными); существующая обновляется; удалением запись не управляется. Поля блока — в `$fillable` модели блока; ограничения на поля — как у [HasMany](has-many.md). Подполя с `visibleWhen()` должны зависеть от поля того же блока, которое доступно всем администраторам (без `readonly()`, `disabled()`, `hidden()`); иначе — ошибка описания.

**Проверка:** ошибки — под полями блока (`banner.title`).

**В списке:** скрыто. На странице просмотра — «поле — значение».
