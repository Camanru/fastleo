<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Support\JsonItem;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Поле ресурса: как значение показывается в форме, проверяется, пишется в модель и выводится в списке.
 *
 * Поле создаётся фабрикой `make()` и настраивается цепочкой методов:
 *
 * ```php
 * Text::make('title', 'Заголовок')->required()->searchable()->sortable();
 * ```
 *
 * Жизненный цикл при сохранении: `rules()` → `dehydrate()` → `fill()` → `beforeSave()` → `save()` модели → `afterSave()`.
 * В форме значение берётся из `hydrate()` (или `default()` при создании), в списке — из `display()`.
 */
abstract class Field
{
    /** Подпись поля в форме, списке и текстах ошибок. */
    protected string $label;

    /** Подсказка под полем. */
    protected ?string $help = null;

    /** Подсказка внутри пустого поля. */
    protected ?string $placeholder = null;

    /** Значение при создании записи (или замыкание без аргументов). */
    protected mixed $default = null;

    /** Поле обязательно. */
    protected bool $required = false;

    /**
     * Свои правила Laravel: массив или замыкание, получающее запись (null при создании).
     *
     * @var array<array-key, mixed>|Closure(?Model): array<array-key, mixed>
     */
    protected array|Closure $rules = [];

    /** Только чтение: значение видно, но не меняется. */
    protected bool $readonly = false;

    /** Недоступно для ввода: значение видно, но не меняется. */
    protected bool $disabled = false;

    /**
     * Скрыть поле везде: `true` или замыкание, получающее запись (null при создании и в списке).
     *
     * @var bool|Closure(?Model): bool
     */
    protected bool|Closure $hidden = false;

    /** Вызывался ли `readonly()`, `disabled()`, `hidden()`, `hideOnCreate()` или `hideOnEdit()` (с любым значением). */
    protected bool $accessModified = false;

    /** Показывать в списке записей. */
    protected bool $showInList = true;

    /** Показывать в форме и на странице просмотра. */
    protected bool $showInForm = true;

    /** Показывать при создании записи. */
    protected bool $showOnCreate = true;

    /** Показывать при редактировании записи. */
    protected bool $showOnEdit = true;

    /** Список можно сортировать по этому полю. */
    protected bool $sortable = false;

    /** Поле участвует в поиске по списку. */
    protected bool $searchable = false;

    /** Поле попадает в выгрузку Excel/CSV и загрузку из неё. */
    protected bool $exportable = true;

    /** Значение на нескольких языках (`fastleo.locales`) в JSON-колонке. */
    protected bool $translatable = false;

    /** Сколько колонок сетки (Grid) занимает поле, от 1 до 12. */
    protected ?int $columnSpan = null;

    /**
     * Своё отображение значения в списке и на странице просмотра.
     *
     * @var (Closure(mixed, Model): (string|Htmlable|null))|null
     */
    protected ?Closure $formatUsing = null;

    /** Свой Blade-шаблон ячейки списка. */
    protected ?string $listView = null;

    /**
     * Условие показа: поле формы и допустимые значения (строками).
     *
     * @var array{field: string, values: list<string>}|null
     */
    protected ?array $visibleWhen = null;

    /** Поле формы, от значения которого зависят варианты (`dependsOn()` у Select и связей). */
    protected ?string $dependsOn = null;

    /** Префикс ключей в запросе для полей вложенного блока (`links.*.`): нужен правилам, ссылающимся на соседние поля. */
    protected string $rulePrefix = '';

    /**
     * Модель, к которой относится поле: ставит ресурс (или вложенный блок) через `forModel()`.
     *
     * @var class-string<Model>|null
     */
    protected ?string $modelClass = null;

    /**
     * Поле по имени атрибута модели. Используйте фабрику `make()`.
     *
     * @throws InvalidArgumentException имя не похоже на имя колонки
     */
    final public function __construct(public readonly string $name, ?string $label = null)
    {
        if (preg_match('/^[A-Za-z_]\w*$/', $name) !== 1) {
            throw new InvalidArgumentException(__('fastleo::exceptions.field_name', ['name' => $name], Fastleo::LOCALE));
        }

        $this->label = $label ?? Str::ucfirst(str_replace('_', ' ', Str::snake($name)));
    }

    /**
     * Создать поле.
     *
     * ```php
     * Text::make('title', 'Заголовок');
     * ```
     */
    public static function make(string $name, ?string $label = null): static
    {
        return new static($name, $label);
    }

    /**
     * Подпись поля.
     *
     * ```php
     * Text::make('title')->label('Заголовок');
     * ```
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Подсказка под полем.
     *
     * ```php
     * Text::make('title')->help('Показывается в шапке страницы');
     * ```
     */
    public function help(?string $help): static
    {
        $this->help = $help;

        return $this;
    }

    /**
     * Подсказка внутри пустого поля.
     *
     * ```php
     * Email::make('email')->placeholder('name@site.ru');
     * ```
     */
    public function placeholder(?string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * Значение при создании записи: значение или замыкание.
     *
     * ```php
     * Date::make('published_at')->default(fn () => now()->toDateString());
     * ```
     */
    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    /**
     * Сделать поле обязательным (или снять обязательность).
     *
     * ```php
     * Text::make('title')->required();
     * ```
     */
    public function required(bool $required = true): static
    {
        $this->required = $required;

        return $this;
    }

    /**
     * Свои правила валидации Laravel поверх автоматических. Замыкание получает запись (null при создании).
     *
     * ```php
     * Text::make('code')->rules(fn (?Model $record) => [Rule::unique('doctors')->ignore($record)]);
     * ```
     *
     * @param  array<array-key, mixed>|Closure(?Model): array<array-key, mixed>  $rules
     */
    public function rules(array|Closure $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * Только чтение: значение видно в форме, но не меняется и не записывается.
     *
     * ```php
     * Text::make('external_id')->readonly();
     * ```
     */
    public function readonly(bool $readonly = true): static
    {
        $this->readonly = $readonly;
        $this->accessModified = true;

        return $this;
    }

    /**
     * Недоступно для ввода: значение видно серым, не меняется и не записывается.
     *
     * ```php
     * Text::make('code')->disabled();
     * ```
     */
    public function disabled(bool $disabled = true): static
    {
        $this->disabled = $disabled;
        $this->accessModified = true;

        return $this;
    }

    /**
     * Скрыть поле в форме, списке и просмотре. Замыкание получает запись (null при создании и в списке).
     * Скрытое поле не проверяется и не записывается.
     *
     * ```php
     * Text::make('note')->hidden(fn (?Model $record) => ! Fastleo::guard()->user()?->is_super);
     * ```
     *
     * @param  bool|Closure(?Model): bool  $hidden
     */
    public function hidden(bool|Closure $hidden = true): static
    {
        $this->hidden = $hidden;
        $this->accessModified = true;

        return $this;
    }

    /**
     * Показывать поле, только когда другое поле формы имеет одно из значений. Скрытое поле
     * не проверяется и не сохраняется (правило Laravel `exclude_unless`).
     *
     * ```php
     * Url::make('video_url')->visibleWhen('type', 'video');
     * Text::make('promo')->visibleWhen('type', ['sale', 'promo']);
     * Date::make('featured_until')->visibleWhen('is_featured', true);
     * ```
     *
     * @param  string|int|bool|\BackedEnum|list<string|int|bool|\BackedEnum>  $values
     *
     * @throws InvalidArgumentException имя поля не похоже на имя колонки
     */
    public function visibleWhen(string $field, string|int|bool|\BackedEnum|array $values): static
    {
        if (preg_match('/^[A-Za-z_]\w*$/', $field) !== 1) {
            throw new InvalidArgumentException(__('fastleo::exceptions.field_name', ['name' => $field], Fastleo::LOCALE));
        }

        $this->visibleWhen = [
            'field' => $field,
            'values' => array_values(array_unique(array_map(self::conditionValue(...), is_array($values) ? $values : [$values]))),
        ];

        return $this;
    }

    /**
     * Условие показа `['field' => ..., 'values' => [...]]` или null.
     *
     * @return array{field: string, values: list<string>}|null
     */
    public function getVisibleWhen(): ?array
    {
        return $this->visibleWhen;
    }

    /**
     * Поле формы, от которого зависят варианты, или null.
     */
    public function getDependsOn(): ?string
    {
        return $this->dependsOn;
    }

    /**
     * Значение поля-зависимости из данных формы (только верхний уровень формы).
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function dependencyFrom(array $data): ?string
    {
        return $this->dependsOn === null ? null : self::dependencyValue($data[$this->dependsOn] ?? null);
    }

    /**
     * Поле-зависимость в форме то же, что у сохранённой записи (или зависимости нет): сохранённое значение
     * зависимого поля остаётся допустимым, даже если вариантов под него уже нет.
     *
     * @param  array<array-key, mixed>  $data
     */
    protected function dependencyUnchanged(?Model $record, array $data): bool
    {
        if ($this->dependsOn === null) {
            return true;
        }

        return $record instanceof Model && $record->exists
            && self::dependencyValue($record->getOriginal($this->dependsOn)) === $this->dependencyFrom($data);
    }

    /**
     * Значение поля-зависимости в виде, который получает замыкание: строка как в форме (enum → value,
     * `true` → `'1'`) или null — не выбрано. Массивы и объекты — null.
     */
    public static function dependencyValue(mixed $value): ?string
    {
        $value = self::conditionValue($value);

        return $value === '' ? null : $value;
    }

    /**
     * Поле находится во вложенном блоке: ключи соседних полей в правилах получают префикс (`links.*.`).
     * Вызывает HasMany/HasOne.
     */
    public function nestedIn(string $prefix): static
    {
        $this->rulePrefix = $prefix;

        return $this;
    }

    /**
     * Значение условия строкой — как его отправляет форма (`true` → `'1'`, enum → значение).
     */
    public static function conditionValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \BackedEnum => (string) $value->value,
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * Не показывать в списке записей.
     *
     * ```php
     * Text::make('subtitle')->hideInList();
     * ```
     */
    public function hideInList(bool $hide = true): static
    {
        $this->showInList = ! $hide;

        return $this;
    }

    /**
     * Показывать в списке, даже если тип поля по умолчанию там скрыт (например, Textarea).
     *
     * ```php
     * Textarea::make('excerpt')->showInList();
     * ```
     */
    public function showInList(bool $show = true): static
    {
        $this->showInList = $show;

        return $this;
    }

    /**
     * Показывать только в списке: в форме поля нет, и оно не записывается.
     *
     * ```php
     * DateTime::make('created_at', 'Создано')->onlyInList();
     * ```
     */
    public function onlyInList(bool $only = true): static
    {
        $this->showInForm = ! $only;
        $this->showInList = $this->showInList || $only;

        return $this;
    }

    /**
     * Не показывать при создании записи.
     *
     * ```php
     * Text::make('code')->hideOnCreate();
     * ```
     */
    public function hideOnCreate(bool $hide = true): static
    {
        $this->showOnCreate = ! $hide;
        $this->accessModified = true;

        return $this;
    }

    /**
     * Не показывать при редактировании записи.
     *
     * ```php
     * Text::make('initial_code')->hideOnEdit();
     * ```
     */
    public function hideOnEdit(bool $hide = true): static
    {
        $this->showOnEdit = ! $hide;
        $this->accessModified = true;

        return $this;
    }

    /**
     * Выгружать ли поле в Excel/CSV и принимать ли его при загрузке из файла.
     *
     * ```php
     * Textarea::make('admin_note', 'Заметка для админов')->exportable(false);
     * ```
     */
    public function exportable(bool $exportable = true): static
    {
        $this->exportable = $exportable;

        return $this;
    }

    /**
     * Значение на нескольких языках: вкладки языков у поля, в колонке — JSON `{"ru": "…", "en": "…"}`
     * (как spatie/laravel-translatable). Языки — `fastleo.locales`, `required()` — только для первого.
     * Работает у Text, Textarea, Markdown, Code и RichText.
     *
     * ```php
     * Text::make('title', 'Заголовок')->translatable()->required();
     * ```
     *
     * @throws LogicException у поля этого типа переводов нет
     */
    public function translatable(bool $translatable = true): static
    {
        if ($translatable && ! $this->canBeTranslated()) {
            throw new LogicException(__('fastleo::exceptions.field_translatable', ['field' => $this->name, 'type' => class_basename($this)], Fastleo::LOCALE));
        }

        $this->translatable = $translatable;

        return $this;
    }

    /**
     * Разрешить сортировку списка по полю (клик по заголовку столбца).
     *
     * ```php
     * Text::make('title')->sortable();
     * ```
     */
    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    /**
     * Искать по полю в строке поиска списка.
     *
     * ```php
     * Text::make('title')->searchable();
     * ```
     */
    public function searchable(bool $searchable = true): static
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * Ширина поля внутри Grid: сколько колонок занять (1–12).
     *
     * ```php
     * Grid::make(3, [Text::make('title')->columnSpan(2), Number::make('price')]);
     * ```
     *
     * @throws InvalidArgumentException число вне 1–12
     */
    public function columnSpan(int $columns): static
    {
        if ($columns < 1 || $columns > 12) {
            throw new InvalidArgumentException(__('fastleo::exceptions.column_span', ['columns' => $columns], Fastleo::LOCALE));
        }

        $this->columnSpan = $columns;

        return $this;
    }

    /**
     * Своё отображение значения в списке и на странице просмотра. Замыкание получает значение
     * атрибута и запись; строка экранируется, HTML можно вернуть через `new HtmlString(...)`.
     *
     * ```php
     * Number::make('price')->formatUsing(fn ($value) => number_format((float) $value, 0, ',', ' ').' ₽');
     * ```
     *
     * @param  Closure(mixed, Model): (string|Htmlable|null)  $format
     */
    public function formatUsing(Closure $format): static
    {
        $this->formatUsing = $format;

        return $this;
    }

    /**
     * Свой Blade-шаблон ячейки списка. В шаблон передаются `$record`, `$field` и `$value`.
     *
     * ```php
     * Text::make('status')->listView('admin.status-badge');
     * ```
     */
    public function listView(string $view): static
    {
        $this->listView = $view;

        return $this;
    }

    /**
     * Привязать поле к модели. Ресурс делает это сам для всех своих полей; вручную — только в тестах.
     *
     * ```php
     * Slug::make('slug')->from('title')->forModel(Article::class);
     * ```
     *
     * @param  class-string<Model>  $modelClass
     */
    public function forModel(string $modelClass): static
    {
        $this->modelClass = $modelClass;

        return $this;
    }

    /**
     * Новый экземпляр модели поля.
     *
     * @throws LogicException поле не привязано к модели
     */
    protected function model(): Model
    {
        if ($this->modelClass === null) {
            throw new LogicException(__('fastleo::exceptions.field_unbound', ['field' => $this->name, 'type' => static::class], Fastleo::LOCALE));
        }

        return new ($this->modelClass);
    }

    /**
     * Подпись поля.
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Подсказка под полем.
     */
    public function getHelp(): ?string
    {
        return $this->help;
    }

    /**
     * Подсказка внутри пустого поля.
     */
    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    /**
     * Поле обязательно.
     */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Поле только для чтения.
     */
    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    /**
     * Поле недоступно для ввода.
     */
    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    /**
     * Значение поля можно менять из формы.
     */
    public function isWritable(): bool
    {
        return ! $this->readonly && ! $this->disabled;
    }

    /**
     * Поле хранит несколько значений (множественный список, метки, связь «многие ко многим»).
     */
    public function isMultiple(): bool
    {
        return false;
    }

    /**
     * Поле получило ограничение доступа вызовом `readonly()`, `disabled()`, `hidden()`, `hideOnCreate()` или `hideOnEdit()` —
     * даже со значением «выключено»: условие вроде `hidden(! $user->isAdmin())` истинно не у всех админов.
     */
    public function hasAccessModifier(): bool
    {
        return $this->accessModified;
    }

    /**
     * Видимость поля зависит от записи: `hideOnCreate()`, `hideOnEdit()` или `hidden(fn ($record) => …)`.
     */
    public function hasRecordVisibility(): bool
    {
        return ! $this->showOnCreate || ! $this->showOnEdit || $this->hidden instanceof Closure;
    }

    /**
     * Поле скрыто для этой записи (null — создание или список).
     */
    public function isHidden(?Model $record = null): bool
    {
        return $this->hidden instanceof Closure ? (bool) ($this->hidden)($record) : $this->hidden;
    }

    /**
     * Поле видно в форме: при создании (record = null) или при редактировании записи.
     */
    public function isVisibleInForm(?Model $record): bool
    {
        if (! $this->showInForm || $this->isHidden($record)) {
            return false;
        }

        return $record instanceof Model && $record->exists ? $this->showOnEdit : $this->showOnCreate;
    }

    /**
     * Поле есть в форме вообще (не `onlyInList()` и не скрыто для текущего админа).
     * Создание или редактирование здесь не учитывается.
     */
    public function isFormField(): bool
    {
        return $this->showInForm && ! $this->isHidden();
    }

    /**
     * Поле видно в списке записей.
     */
    public function isVisibleInList(): bool
    {
        return $this->showInList && ! $this->isHidden();
    }

    /**
     * Поле видно на странице просмотра записи.
     */
    public function isVisibleOnShow(Model $record): bool
    {
        if (! $this->showInForm || $this->isHidden($record)) {
            return false;
        }

        return $this->visibleWhen === null || in_array(self::conditionValue($record->getAttribute($this->visibleWhen['field'])), $this->visibleWhen['values'], true);
    }

    /**
     * Значение поля на нескольких языках.
     */
    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    /**
     * Переводы записи: язык → значение. Модель со spatie/laravel-translatable — через `getTranslations()`,
     * иначе — массив колонки (каст `array`); обычная строка считается текстом основного языка.
     *
     * @return array<string, string>
     */
    public function translations(Model $record): array
    {
        $value = method_exists($record, 'isTranslatableAttribute') && method_exists($record, 'getTranslations') && $record->isTranslatableAttribute($this->name)
            ? $record->getTranslations($this->name)
            : $record->getAttribute($this->name);

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [Fastleo::locales()[0] => $value];
        }

        $translations = [];

        foreach (is_array($value) ? $value : [] as $locale => $text) {
            if (is_string($locale) && is_scalar($text) && (string) $text !== '') {
                $translations[$locale] = (string) $text;
            }
        }

        return $translations;
    }

    /**
     * Запись для показа значения в списке и на просмотре: у многоязычного поля — текст основного
     * языка (или первого заполненного), у обычного — сама запись.
     */
    public function shownRecord(Model $record): Model
    {
        if (! $this->translatable) {
            return $record;
        }

        $translations = $this->translations($record);

        return JsonItem::from([$this->name => $translations[Fastleo::locales()[0]] ?? (array_values($translations)[0] ?? null)]);
    }

    /**
     * Подпись столбца или ошибки для языка: «Заголовок (EN)»; при одном языке — просто подпись.
     */
    public function localeLabel(string $locale): string
    {
        return count(Fastleo::locales()) > 1 ? $this->label.' ('.mb_strtoupper($locale).')' : $this->label;
    }

    /**
     * У поля этого типа могут быть переводы.
     */
    protected function canBeTranslated(): bool
    {
        return false;
    }

    /**
     * Значения языков из запроса: каждый язык обработан `$one` (обрезка, чистка HTML), пустые и
     * неизвестные языки отброшены.
     *
     * @param  Closure(mixed): mixed  $one
     * @return array<string, mixed>
     */
    protected function dehydrateTranslations(mixed $value, Closure $one): array
    {
        $result = [];

        foreach (Fastleo::locales() as $locale) {
            $text = $one(is_array($value) ? ($value[$locale] ?? null) : null);

            if ($text !== null && $text !== '') {
                $result[$locale] = $text;
            }
        }

        return $result;
    }

    /**
     * Поле выгружается в Excel/CSV и загружается из файла.
     */
    public function isExportable(): bool
    {
        return $this->exportable;
    }

    /**
     * Значение для ячейки Excel/CSV: строка, число или null. По умолчанию — значение формы;
     * логическое — «Да»/«Нет», список — через запятую.
     *
     * ```php
     * $field->exportValue($article); // 'Новости'
     * ```
     */
    public function exportValue(Model $record): string|int|float|null
    {
        return self::exportScalar($this->hydrate($record));
    }

    /**
     * Ячейка файла импорта → значение, как его отправила бы форма (дальше — обычная проверка
     * правилами поля и `dehydrate()`). По умолчанию — строка; у полей нескольких значений —
     * список через запятую.
     *
     * ```php
     * Tags::make('keywords')->importValue('акция, новинка'); // ['акция', 'новинка']
     * ```
     */
    public function importValue(mixed $cell): mixed
    {
        $value = self::cellString($cell);

        return $this->isMultiple() ? self::splitList($value, ',') : $value;
    }

    /**
     * Ячейка → обрезанная строка или null: число без «.0», дата — `ГГГГ-ММ-ДД ЧЧ:ММ:СС`
     * (без времени, если оно 00:00:00), логическое — `1`/`0`.
     */
    protected static function cellString(mixed $cell): ?string
    {
        $value = match (true) {
            $cell instanceof \DateTimeInterface => str_replace(' 00:00:00', '', $cell->format('Y-m-d H:i:s')),
            is_bool($cell) => $cell ? '1' : '0',
            is_float($cell) && floor($cell) === $cell && abs($cell) < 1e15 => (string) (int) $cell,
            is_scalar($cell) => trim((string) $cell),
            default => '',
        };

        return $value === '' ? null : $value;
    }

    /**
     * Строка-список → непустые элементы.
     *
     * @param  non-empty-string  $separator
     * @return list<string>
     */
    protected static function splitList(?string $value, string $separator): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode($separator, $value)), fn (string $item): bool => $item !== ''));
    }

    /**
     * Значение формы → значение ячейки.
     */
    protected static function exportScalar(mixed $value): string|int|float|null
    {
        if (is_bool($value)) {
            $text = __($value ? 'fastleo::resources.yes' : 'fastleo::resources.no', locale: Fastleo::LOCALE);

            return is_string($text) ? $text : null;
        }

        return match (true) {
            $value === null, $value === '' => null,
            is_int($value), is_float($value), is_string($value) => $value,
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \Stringable => (string) $value,
            is_array($value) => implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)) ?: null,
            default => null,
        };
    }

    /**
     * По полю можно сортировать список.
     */
    public function isSortable(): bool
    {
        return $this->sortable;
    }

    /**
     * Поле участвует в поиске.
     */
    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    /**
     * Ширина в сетке или null — на всю ширину сетки.
     */
    public function getColumnSpan(): ?int
    {
        return $this->columnSpan;
    }

    /**
     * Свой шаблон ячейки списка или null.
     */
    public function getListView(): ?string
    {
        return $this->listView;
    }

    /**
     * Короткое имя типа поля: `text`, `date-time`. По нему выбирается шаблон формы.
     */
    public function type(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * Blade-шаблон поля в форме.
     */
    public function view(): string
    {
        return 'fastleo::fields.'.$this->type();
    }

    /**
     * Правила валидации поля: `required`/`nullable`, правила типа и свои правила.
     * Ключи — имена в запросе (`tags`, `tags.*`).
     *
     * ```php
     * Text::make('title')->required()->getRules(null); // ['title' => ['required', 'string', 'max:255']]
     * ```
     *
     * @return array<string, list<mixed>>
     */
    public function getRules(?Model $record): array
    {
        $custom = $this->rules instanceof Closure ? ($this->rules)($record) : $this->rules;

        if ($this->translatable) {
            $rules = [$this->name => [...$this->conditionRules(), $this->presenceRule($record), 'array']];

            foreach (Fastleo::locales() as $index => $locale) {
                $rules[$this->name.'.'.$locale] = [$index === 0 && $this->required ? 'required' : 'nullable', ...$this->typeRules(), ...array_values($custom)];
            }

            return $rules;
        }

        $rules = [$this->name => [
            ...$this->conditionRules(),
            $this->presenceRule($record),
            ...$this->typeRules(),
            ...array_values($custom),
        ]];

        foreach ($this->nestedRules() as $key => $nested) {
            $rules[$this->name.'.'.$key] = $nested;
        }

        return $rules;
    }

    /**
     * Подписи поля для текстов ошибок: ключ в запросе → подпись (`tags` и `tags.*`).
     *
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        $attributes = [$this->name => $this->label, $this->name.'.*' => $this->label];

        if ($this->translatable) {
            foreach (Fastleo::locales() as $locale) {
                $attributes[$this->name.'.'.$locale] = $this->localeLabel($locale);
            }
        }

        return $attributes;
    }

    /**
     * Правило условия показа: `exclude_unless:поле,значение,...` — без него скрытое поле проверялось бы.
     *
     * @return list<string>
     */
    protected function conditionRules(): array
    {
        if ($this->visibleWhen === null) {
            return [];
        }

        $values = array_map(fn (string $value): string => '"'.str_replace('"', '""', $value).'"', $this->visibleWhen['values']);

        return ['exclude_unless:'.$this->rulePrefix.$this->visibleWhen['field'].','.implode(',', $values)];
    }

    /**
     * Первое правило поля: `required` или `nullable`.
     */
    protected function presenceRule(?Model $record): string
    {
        return $this->required ? 'required' : 'nullable';
    }

    /**
     * Значение для формы: из записи или значение по умолчанию при создании.
     */
    public function formValue(?Model $record): mixed
    {
        if ($record instanceof Model && $record->exists) {
            return $this->translatable ? $this->translations($record) : $this->hydrate($record);
        }

        $default = $this->formatDefault($this->default instanceof Closure ? ($this->default)() : $this->default);

        if ($this->translatable && ! is_array($default)) {
            return $default === null || $default === '' ? [] : [Fastleo::locales()[0] => $default];
        }

        return $default;
    }

    /**
     * Что форма новой записи отправит, если админ поле не трогал (после `dehydrate()`).
     * По нему пустой новый вложенный блок отличается от заполненного.
     */
    public function untouchedValue(): mixed
    {
        return $this->dehydrate($this->formValue(null));
    }

    /**
     * Значение по умолчанию в виде, который понимает поле формы (дата — строкой нужного формата).
     */
    protected function formatDefault(mixed $value): mixed
    {
        return $value;
    }

    /**
     * Значение атрибута записи в виде, который понимает поле формы.
     */
    public function hydrate(Model $record): mixed
    {
        return $record->getAttribute($this->name);
    }

    /**
     * Проверенное значение из запроса → значение для модели. Пустая строка становится null.
     */
    public function dehydrate(mixed $value): mixed
    {
        if ($this->translatable) {
            return $this->dehydrateTranslations($value, self::cleanString(...));
        }

        return self::cleanString($value);
    }

    /**
     * Строка без пробелов по краям; пустая — null.
     */
    protected static function cleanString(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return $value === '' ? null : $value;
    }

    /**
     * Записать значение в модель через `fill()`: соблюдаются `$fillable`/`$guarded`, касты и мутаторы модели.
     * Что поле разрешено моделью, проверяет контроллер до сохранения (`Resource::assertFillable()`).
     */
    public function fill(Model $record, mixed $value): void
    {
        if ($this->translatable) {
            $this->fillTranslations($record, is_array($value) ? $value : []);

            return;
        }

        $record->fill([$this->name => $value]);
    }

    /**
     * Записать переводы: модель со spatie/laravel-translatable — `replaceTranslations()`,
     * иначе массив в колонку с кастом `array` (без каста — понятная ошибка).
     *
     * @param  array<array-key, mixed>  $translations
     *
     * @throws LogicException у колонки нет каста `array`
     */
    protected function fillTranslations(Model $record, array $translations): void
    {
        if (method_exists($record, 'isTranslatableAttribute') && method_exists($record, 'replaceTranslations') && $record->isTranslatableAttribute($this->name)) {
            $record->replaceTranslations($this->name, $translations);

            return;
        }

        if (! $record->hasCast($this->name)) {
            throw new LogicException(__('fastleo::exceptions.field_needs_array_cast', ['field' => $this->name, 'model' => $record::class], Fastleo::LOCALE));
        }

        $record->fill([$this->name => $translations]);
    }

    /**
     * Связи модели, которые нужны полю в списке: подгружаются одним запросом (`with()`).
     *
     * @return list<string>
     */
    public function eagerLoad(): array
    {
        return [];
    }

    /**
     * Поле пишет атрибут модели (а не связь) — значит, модель должна разрешать его в `$fillable`.
     */
    public function writesAttribute(): bool
    {
        return true;
    }

    /**
     * Действия после заполнения всех полей и до `save()` (например, slug из заголовка).
     * Вызывается для всех записываемых полей формы. У простых полей ничего не делает.
     */
    public function beforeSave(Model $record): void
    {
        //
    }

    /**
     * Действия после сохранения записи (связи, вложенные блоки, файлы) в той же транзакции.
     * Вызывается только для полей, пришедших в запросе; `$value` — результат `dehydrate()`.
     */
    public function afterSave(Model $record, mixed $value): void
    {
        //
    }

    /**
     * Значение для списка и страницы просмотра. Строка экранируется при выводе.
     */
    public function display(Model $record): string|Htmlable|null
    {
        $value = $record->getAttribute($this->name);

        if ($this->formatUsing instanceof Closure) {
            return ($this->formatUsing)($value, $record);
        }

        return $this->format($value);
    }

    /**
     * Значение для ячейки списка. По умолчанию — как на странице просмотра; длинные типы его сокращают.
     */
    public function listDisplay(Model $record): string|Htmlable|null
    {
        return $this->display($record);
    }

    /**
     * Отображение значения по умолчанию для типа поля.
     */
    protected function format(mixed $value): string|Htmlable|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
    }

    /**
     * Правила, которые тип поля добавляет сам (после required/nullable).
     *
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        return [];
    }

    /**
     * Правила для вложенных значений (`tags.*`) — для полей-массивов.
     *
     * @return array<string, list<mixed>>
     */
    protected function nestedRules(): array
    {
        return [];
    }
}
