# Enum

Список вариантов из PHP backed enum (`enum Status: string` или `int`).

```php
use Fastleo\Fields\Enum;

Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required()->sortable();

// Несколько значений
Enum::make('channels', 'Каналы')->enum(Channel::class)->multiple();

// Цветные метки в списке
Enum::make('status', 'Статус')->enum(ArticleStatus::class)->badges();
```

**Колонка:** `$table->string('status', 20)`, в модели каст `'status' => ArticleStatus::class`. Для `multiple()` — `json` и каст `'channels' => AsEnumCollection::of(Channel::class)` или `'array'`.

**Проверка:** значение — одно из значений enum; у `multiple()` — массив таких значений без повторов.

**В списке:** подпись варианта (у `multiple()` — подписи через запятую); с `badges()` — цветные метки. Значение в базе, которого нет в enum, с enum-кастом модели даёт ошибку (`ValueError` при чтении записи) — меняя enum, сначала обновите данные; у `multiple()` с кастом `'array'` такое значение показывается как есть.

**Подписи вариантов** — метод `label()` enum, если он есть, иначе имя случая:

```bash
php artisan make:enum App/Enums/ArticleStatus --string   # app/Enums/ArticleStatus.php
```

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

**Цветные метки.** Добавьте рядом с `label()` метод `tone()`, который возвращает `success`, `warning`, `danger`, `info` или `neutral`, и включите `->badges()`. Нет метода или он вернул другое — метка нейтральная.

```php
public function tone(): string
{
    return match ($this) {
        self::Draft => 'neutral',
        self::Published => 'success',
    };
}
```

| Метод | Что делает |
|---|---|
| `enum(Status::class)` | Enum вариантов (обязательно; не enum — ошибка описания) |
| `multiple()` | Можно выбрать несколько |
| `badges()` | Цветные метки в списке и на просмотре; тон берётся из метода `tone()` enum, можно задать массивом `badges(['draft' => 'neutral'])` ([Select](select.md)) |

Общие методы — [README](README.md#общие-методы). Текст пустого варианта — общий `placeholder('Выберите')`.

То же умеет `Select::make()->options(Status::class)`; `Enum` короче и сразу ругается, если enum не указан. Остальное — как у [Select](select.md).

← [Все поля](README.md)
