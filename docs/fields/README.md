# Поля

Поле связывает колонку модели с формой, списком и страницей просмотра. Создаётся фабрикой `make(имя, подпись)` и настраивается цепочкой методов:

```php
use Fastleo\Fields\Text;

Text::make('title', 'Заголовок')->required()->searchable()->sortable();
```

Имя поля — имя атрибута модели (колонки): латиница, цифры и подчёркивание. Подпись можно не указывать — тогда она получится из имени (`meta_title` → «Meta title»).

## Типы

| Поле | Для чего | Колонка |
|---|---|---|
| [Text](text.md) | строка | `string` |
| [Textarea](textarea.md) | многострочный текст | `text` |
| [Number](number.md) | целое или дробное число | `integer`, `decimal` |
| [Checkbox](checkbox.md) | галочка да/нет | `boolean` |
| [Toggle](toggle.md) | переключатель вкл/выкл | `boolean` |
| [Select](select.md) | выбор из списка (один или несколько) | `string` / `json` |
| [Date](date.md) | дата | `date` |
| [DateTime](date-time.md) | дата и время | `dateTime`, `timestamp` |
| [Time](time.md) | время | `time` |
| [Email](email.md) | email | `string` |
| [Url](url.md) | адрес страницы | `string` |
| [Tel](tel.md) | телефон | `string` |
| [Color](color.md) | цвет HEX | `string` |
| [Hidden](hidden.md) | скрытое значение | любая |
| [Radio](radio.md) | один вариант кнопками | `string` |
| [Range](range.md) | число ползунком | `integer` |
| [Enum](enum.md) | варианты из PHP enum | `string` / `integer` |
| [Password](password.md) | пароль (хеш, пустое — не менять) | `string` |
| [Secret](secret.md) | токен, ключ API (не показывается, пустое — не менять) | `text` + каст `encrypted` |
| [Slug](slug.md) | адрес записи из заголовка | `string`, уникальный |
| [Tags](tags.md) | метки свободным вводом | `json` + каст `array` |
| [Code](code.md) | код, HTML-вставка без чистки | `text` |
| [Markdown](markdown.md) | текст в Markdown с просмотром | `text` |
| [RichText](rich-text.md) | визуальный редактор, HTML чистится | `longText` |
| [Image](image.md) | картинка из файлового менеджера | `string` |
| [File](file.md) | файл из файлового менеджера | `string` |
| [Gallery](gallery.md) | несколько картинок с порядком | `json` + каст `array` |
| [BelongsTo](belongs-to.md) | связь с записью другой таблицы | `foreignId` |
| [BelongsToMany](belongs-to-many.md) | связь многие ко многим | промежуточная таблица |
| [HasMany](has-many.md) | вложенные записи блоками | таблица блоков |
| [HasOne](has-one.md) | одна вложенная запись | таблица блока |
| [Repeater](repeater.md) | повторяющаяся группа полей | `json` + каст `array` |
| [KeyValue](key-value.md) | пары «название — значение» | `json` + каст `array` |
| [Seo](seo.md) | заголовок, описание, картинка и запрет индексации | `json` + каст `array` |
| [Blocks](../blocks.md) | конструктор из блоков разных типов | `json` + каст `array` |

Раскладка формы (вкладки, секции, колонки) — [layout.md](../layout.md).

Своё поле (наследник готового с собственным шаблоном) — [custom.md](custom.md).

## Общие методы

Есть у всех полей.

| Метод | Что делает |
|---|---|
| `label('Заголовок')` | Подпись поля в форме, списке и текстах ошибок |
| `help('Текст')` | Подсказка под полем |
| `placeholder('Текст')` | Подсказка внутри пустого поля |
| `default($value)` | Значение при создании записи; можно замыкание: `default(fn () => now())` — даты приводятся к формату поля сами |
| `translatable()` | Значение на нескольких языках — вкладки языков у поля; у Text, Textarea, Markdown, Code, RichText — [translations.md](../translations.md) |
| `exportable(false)` | Не выгружать поле в Excel/CSV и не загружать из файла — [import-export.md](../import-export.md) |
| `required()` | Обязательное поле (без него поле `nullable`). У `Checkbox`/`Toggle` не требует отметки — для «согласия» используйте `rules(['accepted'])` |
| `rules([...])` | Свои правила Laravel поверх автоматических; можно замыкание, получающее запись |
| `readonly()` | Значение видно, но не меняется и не записывается |
| `disabled()` | Поле серое, значение не меняется и не записывается |
| `hidden()` | Скрыть везде (форма, список, просмотр); можно замыкание, получающее запись — `null` при создании и в списке, поэтому пишите `$record?->...` |
| `hideInList()` | Не показывать столбцом в списке |
| `showInList()` | Показать в списке поле, скрытое там по умолчанию (`Textarea`) |
| `onlyInList()` | Только столбец списка: в форме и просмотре нет, не записывается |
| `hideOnCreate()` | Нет в форме создания |
| `hideOnEdit()` | Нет в форме редактирования |
| `sortable()` | Сортировка списка по клику на заголовок столбца |
| `searchable()` | Поиск по полю в строке поиска списка |
| `columnSpan(2)` | Ширина внутри `Grid`, в колонках (1–12) |
| `visibleWhen('type', 'video')` | Показывать, только когда другое поле формы имеет одно из значений; скрытое не проверяется и не сохраняется ([layout.md](../layout.md#зависимые-поля)) |
| `formatUsing(fn ($value, $record) => ...)` | Своё отображение в списке и на просмотре |
| `listView('admin.cell')` | Свой Blade-шаблон значения в списке и на просмотре |

Примеры:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

// Уникальный код: при редактировании своя запись не мешает
Text::make('code', 'Код')
    ->required()
    ->rules(fn (?Model $record) => [Rule::unique('doctors', 'code')->ignore($record)]);

// Поле только для суперадмина
Text::make('note', 'Заметка')
    ->hidden(fn (?Model $record) => ! auth('fastleo')->user()?->is_super);

// Цена с пробелами и рублём в списке
Number::make('price', 'Цена')->formatUsing(fn ($value) => $value === null ? null : number_format((float) $value, 0, ',', ' ').' ₽');

// HTML в списке — только через HtmlString (обычная строка экранируется)
Text::make('status')->formatUsing(fn ($value) => new HtmlString('<b>'.e($value).'</b>'));

// Дата создания только в списке
DateTime::make('created_at', 'Создано')->onlyInList()->sortable();
```

`hideOnCreate()` и `hideOnEdit()` касаются только формы: на странице просмотра такие поля видны.

Шаблон из `listView()` (в списке и на странице просмотра) получает переменные `$record` (модель), `$field` (поле) и `$value` (значение атрибута):

```blade
{{-- resources/views/admin/cell.blade.php --}}
<span class="font-medium">{{ $value }}</span>
```

Классы Tailwind в шаблонах сайта не работают — стили пакета собраны заранее. Пишите обычный CSS в файле из `fastleo.css` ([настройки](../configuration.md)).

## Проверка значений

Каждое поле добавляет правила само: `nullable` или `required`, затем правила своего типа (`Text` — строка не длиннее 255, `Email` — формат адреса и т. д.), затем ваши `rules()`. Тексты ошибок — по-русски. Подробнее — [validation.md](../validation.md).

## Запись в модель

Значение пишется через `fill()` модели — поле должно быть в `$fillable`. Пустая строка сохраняется как `null`, пробелы по краям обрезаются (кроме `Password`). Поля `readonly()`, `disabled()`, `hidden()`, `onlyInList()` и скрытые в текущей форме (`hideOnCreate()`/`hideOnEdit()`) не записываются. Подробнее — [resources.md](../resources.md#запись-в-модель).
