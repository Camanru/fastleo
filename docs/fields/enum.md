# Enum

Список вариантов из PHP backed enum (`enum Status: string` или `int`). Подпись варианта — метод `label()` enum, если он есть, иначе имя случая.

```php
use Fastleo\Fields\Enum;

Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required()->sortable();

// Несколько значений
Enum::make('channels', 'Каналы')->enum(Channel::class)->multiple();
```

**Колонка:** `$table->string('status', 20)`, в модели каст `'status' => ArticleStatus::class`. Для `multiple()` — `json` и каст `'channels' => AsEnumCollection::of(Channel::class)` или `'array'`.

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

**Цветные метки.** Добавьте рядом с `label()` метод `tone()`, который возвращает `success`, `warning`, `danger`, `info` или `neutral`, и включите `->badges()`:

```php
public function tone(): string
{
    return match ($this) {
        self::Draft => 'neutral',
        self::Published => 'success',
    };
}
```

Нет метода или он вернул другое — метка нейтральная.

**Проверка:** значение — одно из значений enum.

| Метод | Что делает |
|---|---|
| `enum(Status::class)` | Enum вариантов (обязательно; не enum — ошибка описания) |
| `multiple()` | Можно выбрать несколько |
| `placeholder('Выберите')` | Текст пустого варианта |
| `badges()` | Цветные метки в списке и на просмотре; тон берётся из метода `tone()` enum, можно задать массивом `badges(['draft' => 'neutral'])` ([Select](select.md)) |

То же умеет `Select::make()->options(Status::class)`; `Enum` короче и сразу ругается, если enum не указан. Остальное — как у [Select](select.md).
