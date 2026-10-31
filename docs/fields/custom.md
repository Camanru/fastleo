# Своё поле

Своё поле — класс-наследник готового поля и Blade-шаблон формы, если готовых полей не хватает.

```bash
php artisan fastleo:make-field Phone
```

```php
use App\Fastleo\Fields\Phone;

Phone::make('phone', 'Телефон')->required();
```

Команда создаёт два файла: `app/Fastleo/Fields/Phone.php` (наследник [Text](text.md)) и `resources/views/fastleo/fields/phone.blade.php`. Дальше поле подключается в ресурсе, как любое другое (пример выше).

**Колонка:** как у родителя (`Text` — `string`).

**Проверка:** как у родителя (`Text` — строка до 255 символов); свои правила — переопределите `typeRules()`.

**В списке:** как у родителя (`Text` — текст как есть); свой вид — переопределите `format()`. Свой вид ячейки можно задать и без нового класса — методом `listView()` ([общие методы](README.md#общие-методы)).

**Другой родитель.** Заготовка рассчитана на `Text`. Чтобы хранить значение иначе (число, длинный текст, выбор из списка):

1. В классе замените `extends Text` на нужного родителя: `Number`, `Textarea`, `Select`…
2. Перепишите шаблон по образцу шаблона родителя: `vendor/camanru/fastleo/resources/views/fields/<тип>.blade.php`.

Шаблон из заготовки вызывает `$field->getMaxLength()`: он подходит для `Text` и его наследников и для `Textarea`, а у `Number` и `Select` такого метода нет.

**Шаблон формы** получает переменные `$field`, `$value`, `$name`, `$path`, `$id`.

- Рамку с подписью, подсказкой и ошибкой рисует `<x-fastleo::field :field="$field" :id="$id" :path="$path">`, внутри — свой `<input>`.
- Значение берите из `old($path, $value)`, как в заготовке.

**Где ищется шаблон.** Заготовка `make-field` задаёт `view()` сама. Без своего `view()` шаблон ищется автоматически: сначала у класса поля и его родителей с сайта, затем берётся шаблон ближайшего поля пакета:

- у класса сайта — `resources/views/fastleo/fields/{тип}.blade.php` (папка, куда пишет `make-field`);
- нет такого — шаблон поля пакета, от которого класс унаследован (у `Image` — `file`, у `Email` — `text`).

`{тип}` — имя класса в kebab-case: `SiteRichText` → `site-rich-text`. Примеры:

- **Наследник готового поля без шаблона** (`SiteRichText extends RichText`, `SiteImage extends Image`) берёт шаблон поля пакета (`rich-text`, `file`).
- **Наследник со своим шаблоном** — положите `resources/views/fastleo/fields/site-rich-text.blade.php` (или `site-image.blade.php`), и он подхватится без `view()`.
- **Шаблон сайта не подменяет поле пакета**, даже если имена совпали: своё поле `App\Fastleo\Fields\Image` и его `fastleo/fields/image.blade.php` не меняют поле `Fastleo\Fields\Image`.
- **Заменить шаблон самого поля пакета** для всего сайта — скопируйте его в `resources/views/vendor/fastleo/fields/<тип>.blade.php` ([оформление](../theming.md)).

**Поля со скриптами.** Редактор RichText и обрезка картинок (Image с `crop()`) работают на скриптах, которые подключает шаблон пакета. Если вы пишете свой шаблон для наследника такого поля, выберите один из двух способов:

1. **Дополнить шаблон пакета** (проще). Вставьте его целиком — `@include('fastleo::fields.rich-text')`, у File и Image — `@include('fastleo::fields.file')` — и добавьте свою разметку до или после. Подпись и редактор выведет вставленный шаблон, второй раз их не рисуйте.
2. **Заменить шаблон целиком.** Скопируйте из шаблона пакета блок `@pushOnce('fastleo-scripts', 'fastleo-hugerte')` (у File и Image — `'fastleo-cropper'`) вместе с разметкой, которую ищет скрипт (`x-data="fastleoRichText(...)"`, у File и Image — `x-data="fastleoFile(...)"`). Без блока `@pushOnce` скрипт не загрузится и редактор не запустится.

| Метод для переопределения | Что делает |
|---|---|
| `view()` | Имя Blade-шаблона формы (в заготовке — `fastleo.fields.phone`) |
| `typeRules()` | Правила проверки типа (`['string', 'max:255']`) |
| `hydrate(Model $record)` | Значение для формы из записи |
| `dehydrate(mixed $value)` | Значение из формы для записи |
| `format(mixed $value)` | Вид значения в списке и на странице просмотра |

Общие методы — [README](README.md#общие-методы).

← [Все поля](README.md)
