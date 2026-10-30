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

**Наследник готового поля.** Если у наследника нет своего шаблона (`SiteRichText extends RichText`), форма берёт шаблон ближайшего родителя пакета (`rich-text`); `view()` переопределять не нужно.

В списке свой вид ячейки можно задать и без нового класса — методом `listView()` ([общие методы](README.md#общие-методы)).
