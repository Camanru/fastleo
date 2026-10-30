# Своё поле

Если готовых полей не хватает, сайт описывает своё: класс-наследник готового поля и Blade-шаблон формы. Создаёт оба файла команда:

```bash
php artisan fastleo:make-field Phone
```

Появятся `app/Fastleo/Fields/Phone.php` (наследник [Text](text.md)) и `resources/views/fastleo/fields/phone.blade.php`. Использование в ресурсе:

```php
use App\Fastleo\Fields\Phone;

Phone::make('phone', 'Телефон')->required();
```

**Колонка и проверка** — как у родителя (`Text`: `string`, до 255 символов). Для другого хранения возьмите другого родителя (`Number`, `Textarea`, `Select`…): смените `extends` и перепишите шаблон по образцу шаблона родителя (`vendor/camanru/fastleo/resources/views/fields/<тип>.blade.php`) — заготовка шаблона рассчитана на `Text` (использует `getMaxLength()`, которого нет у `Number` и `Select`).

**Шаблон формы** получает `$field`, `$value`, `$name`, `$path`, `$id`. Рамку с подписью, подсказкой и ошибкой рисует `<x-fastleo::field :field="$field" :id="$id" :path="$path">`, внутри — свой `<input>`; значение берите из `old($path, $value)`, как в заготовке.

| Метод для переопределения | Что делает |
|---|---|
| `view()` | имя Blade-шаблона формы (в заготовке — `fastleo.fields.phone`) |
| `typeRules()` | правила проверки типа (`['string', 'max:255']`) |
| `hydrate(Model $record)` | значение для формы из записи |
| `dehydrate(mixed $value)` | значение из формы для записи |
| `format(mixed $value)` | вид значения в списке и на странице просмотра |

**Где ищется шаблон.** Для класса поля и его родителей по очереди: у класса сайта — `resources/views/fastleo/fields/{тип}.blade.php` (папка, куда пишет `make-field`), у класса пакета — его шаблон `fastleo::fields.{тип}`; берётся первый найденный. `{тип}` — имя класса в kebab-case: `SiteRichText` → `site-rich-text`.

- **Наследник готового поля без шаблона** (`SiteRichText extends RichText`) берёт шаблон ближайшего родителя пакета (`rich-text`); `view()` переопределять не нужно.
- **Наследник со своим шаблоном** — положите `resources/views/fastleo/fields/site-rich-text.blade.php`, и он подхватится так же без `view()`.
- **Шаблон сайта не подменяет поле пакета**, даже если имена совпали: своё поле `App\Fastleo\Fields\Image` и его `fastleo/fields/image.blade.php` не меняют поле `Fastleo\Fields\Image`.
- **Заменить шаблон самого поля пакета** для всего сайта — скопируйте его в `resources/views/vendor/fastleo/fields/<тип>.blade.php` ([оформление](../theming.md)).

**Скрипты полей.** Редактор RichText и обрезка картинок (File, Image с `crop()`) подключают свои скрипты из шаблона пакета. Свой шаблон для наследника такого поля:

- **дополняет шаблон пакета** — вставьте его целиком: `@include('fastleo::fields.rich-text')` (у файловых полей — `@include('fastleo::fields.file')`) и добавьте свою разметку до или после; поле с подписью и редактором выведет вставленный шаблон, второй раз их не рисуйте;
- **заменяет шаблон целиком** — скопируйте из шаблона пакета блок `@pushOnce('fastleo-scripts', 'fastleo-hugerte')` (у файловых — `'fastleo-cropper'`) вместе с разметкой, которую ищет скрипт (`x-data="fastleoRichText(...)"`). Без блока `@pushOnce` скрипт не загрузится и редактор не запустится.

В списке свой вид ячейки можно задать и без нового класса — методом `listView()` ([общие методы](README.md#общие-методы)).
