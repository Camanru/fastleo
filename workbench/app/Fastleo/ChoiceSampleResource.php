<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\Checkbox;
use Fastleo\Fields\Date;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Radio;
use Fastleo\Fields\Select;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Fastleo\Fields\Time;
use Fastleo\Fields\Toggle;
use Fastleo\Filters\BooleanFilter;
use Fastleo\Filters\DateRangeFilter;
use Fastleo\Filters\SelectFilter;
use Fastleo\Layout\Aside;
use Fastleo\Layout\Grid;
use Fastleo\Layout\Section;
use Fastleo\Resource;
use Workbench\App\Enums\Priority;
use Workbench\App\Models\ChoiceSample;

/**
 * Витрина «Выбор и даты»: Select (один вариант, `multiple()` плашками, цветные метки `badges()`, зависимый список
 * `dependsOn()`, `emptyValue()`), Enum с `label()`/`tone()` на целочисленном enum, Radio, Checkbox, Toggle
 * с быстрым переключателем, Tags с подсказками, Date, DateTime, Time и поля, видимые по условию (`visibleWhen()`).
 */
final class ChoiceSampleResource extends Resource
{
    protected static string $model = ChoiceSample::class;

    protected static ?string $label = 'Выбор и даты';

    protected static ?string $singular = 'Пример выбора и дат';

    protected static ?string $description = 'Списки, кнопки, галочки, метки и даты. У каждого поля в подсказке — какую возможность оно показывает.';

    protected static string $icon = 'list-checks';

    protected static ?string $group = 'Витрина: поля';

    protected static int $position = 20;

    /**
     * Поля: секции списков и кнопок, даты — в боковой панели.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required()->searchable()->sortable(),
            Section::make('Списки', [
                Grid::make(3, [
                    Select::make('size', 'Размер')->options(['s' => 'S', 'm' => 'M', 'l' => 'L'])->placeholder('Выберите размер')->default('m')->inlineEditable()
                        ->help('Select: options([...]), placeholder(), default(\'m\'), inlineEditable()'),
                    Select::make('stage', 'Этап')->options(['new' => 'Новый', 'packing' => 'Сборка', 'shipped' => 'Отправлен'])
                        ->badges(['new' => 'info', 'packing' => 'warning', 'shipped' => 'success'])->sortable()
                        ->help('badges([...]) — цветные метки в списке'),
                    Select::make('shelf', 'Полка')->options([1 => 'Полка 1', 2 => 'Полка 2', 3 => 'Полка 3'])->placeholder('Без полки')->emptyValue(0)
                        ->help('emptyValue(0) — пустой выбор записывается нулём (колонка NOT NULL)'),
                ]),
                Select::make('colors', 'Цвета')->options(['red' => 'Красный', 'green' => 'Зелёный', 'blue' => 'Синий', 'black' => 'Чёрный'])->multiple()
                    ->badges(['red' => 'danger', 'green' => 'success', 'blue' => 'info'])
                    ->help('multiple() — плашки, json-колонка с кастом array; badges() — метка на каждый вариант'),
                Grid::make(2, [
                    Select::make('brand', 'Марка')->options(['apple' => 'Apple', 'samsung' => 'Samsung'])->hideInList()->help('Управляет списком «Модель»'),
                    Select::make('model', 'Модель')
                        ->options(fn (mixed $brand): array => match ($brand) {
                            'apple' => ['iphone' => 'iPhone', 'ipad' => 'iPad'],
                            'samsung' => ['galaxy' => 'Galaxy', 'tab' => 'Galaxy Tab'],
                            default => [],
                        })
                        ->dependsOn('brand')->hideInList()->help('dependsOn(\'brand\') — варианты зависят от марки'),
                ]),
                Enum::make('priority', 'Приоритет')->enum(Priority::class)->badges()->required()->default(Priority::Normal->value)->sortable()
                    ->help('Enum на int-enum: подписи из label(), цвета меток из tone()'),
            ])->description('Выбор из готовых вариантов'),
            Section::make('Кнопки и галочки', [
                Radio::make('delivery', 'Доставка')->options(['pickup' => 'Самовывоз', 'courier' => 'Курьер'])->default('pickup')
                    ->help('Radio: один вариант кнопками'),
                Text::make('address', 'Адрес доставки')->required()->visibleWhen('delivery', 'courier')->hideInList()
                    ->help('visibleWhen(\'delivery\', \'courier\') — видно и обязательно только для курьера'),
                Checkbox::make('is_gift', 'Подарочная упаковка')->hideInList()->help('Checkbox: галочка да/нет'),
                Text::make('gift_note', 'Текст открытки')->visibleWhen('is_gift', true)->hideInList()->help('visibleWhen(\'is_gift\', true)'),
                Tags::make('labels', 'Метки')->suggestions(['хит', 'новинка', 'скидка'])->maxTagLength(30)->hideInList()
                    ->help('Tags: свободный ввод, suggestions([...]), maxTagLength(30)'),
            ])->collapsible(),
            Aside::make([
                Toggle::make('is_active', 'Активно')->default(true)->sortable()->quickToggle()->help('Toggle: quickToggle() — переключатель прямо в списке'),
                Date::make('available_on', 'Доступно с')->sortable()->help('Date'),
                DateTime::make('starts_at', 'Начало акции')->hideInList()->help('DateTime: в часовом поясе панели'),
                Time::make('opens_at', 'Открытие')->hideInList()->help('Time'),
            ]),
        ];
    }

    /**
     * Фильтры по вариантам полей: список, множественный список, enum, галочка, даты.
     */
    public function filters(): array
    {
        return [
            SelectFilter::make('stage'),
            SelectFilter::make('colors'),
            SelectFilter::make('priority'),
            BooleanFilter::make('is_active'),
            DateRangeFilter::make('available_on'),
        ];
    }
}
