<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Closure;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Field;
use Fastleo\Fields\Number;
use Fastleo\Fields\Select;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Text;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use WeakMap;

/**
 * Правка значения прямо в ячейке списка (`Field::inlineEditable()`): какие поля подходят и что нужно ячейке.
 *
 * Подходят текст, число, список (одиночный выбор) и `BelongsTo` без зависимостей от других полей формы.
 * Варианты списка считаются один раз за запрос, а не на каждую строку.
 *
 * ```php
 * InlineEdit::prepare($resource, $field, $record, $allows); // ['kind' => 'select', 'value' => 'new', 'options' => [...]] или null
 * ```
 */
final class InlineEdit
{
    /** Предел длины значения, которое принимает ячейка. */
    public const int MAX_LENGTH = 1000;

    /**
     * Варианты списков, уже посчитанные в запросе: раздел.поле → варианты (null — слишком много для ячейки).
     *
     * @var WeakMap<Request, array<string, list<array{value: string, label: string}>|null>>|null
     */
    private static ?WeakMap $memo = null;

    /**
     * Тип поля подходит для правки в ячейке.
     */
    public static function supports(Field $field): bool
    {
        return ($field instanceof Text && ! $field instanceof Slug) || $field instanceof Number || $field instanceof Select || $field instanceof BelongsTo;
    }

    /**
     * Данные для ячейки или null, если править нельзя: нет права `update`, поле не для записи, вариантов слишком много
     * (список с поиском по серверу в ячейку не помещается).
     *
     * @param  Closure(string, ?Model): bool  $allows  проверка прав раздела
     *                                                 `tones` — тон цветной метки каждого варианта (список с `badges()`), чтобы ячейка красилась как метка.
     * @return array{kind: string, value: string, options: list<array{value: string, label: string}>, tones: array<string, string>}|null
     */
    public static function prepare(Resource $resource, Field $field, Model $record, Closure $allows): ?array
    {
        if (! $field->isInlineEditable() || ! $field->isWritable() || ! $field->isConditionMet($record) || ! $field->isVisibleInForm($record) || ! $allows('update', $record)) {
            return null;
        }

        $current = $field->hydrate($record);
        $value = is_scalar($current) ? (string) $current : '';

        if (! $field instanceof Select && ! $field instanceof BelongsTo) {
            return ['kind' => $field instanceof Number ? 'number' : 'text', 'value' => $value, 'options' => [], 'tones' => []];
        }

        $options = self::options($resource, $field);

        if ($options === null) {
            return null;
        }

        // Сохранённое значение, которого нет среди вариантов, остаётся выбранным: ячейка не меняет его молча.
        if ($value !== '' && ! in_array($value, array_column($options, 'value'), true)) {
            $options[] = ['value' => $value, 'label' => $value];
        }

        $tones = [];

        if ($field instanceof Select && $field->hasBadges()) {
            foreach ($options as $option) {
                $tones[$option['value']] = $field->badgeTone($option['value']);
            }
        }

        return ['kind' => 'select', 'value' => $value, 'options' => $options, 'tones' => $tones];
    }

    /**
     * Варианты списка (с пустым, если поле не обязательно) или null, если их слишком много для ячейки.
     *
     * @return list<array{value: string, label: string}>|null
     */
    private static function options(Resource $resource, Select|BelongsTo $field): ?array
    {
        $key = $resource::slug().'.'.$field->name;
        $request = request();
        $memo = self::$memo ??= new WeakMap;
        $cached = $memo[$request] ?? [];

        if (! array_key_exists($key, $cached)) {
            $cached[$key] = self::load($field);
            $memo[$request] = $cached;
        }

        return $cached[$key];
    }

    /**
     * @return list<array{value: string, label: string}>|null
     */
    private static function load(Select|BelongsTo $field): ?array
    {
        if ($field instanceof BelongsTo) {
            if ($field->isRemote()) {
                return null;
            }

            $options = $field->preloadedOptions();
        } else {
            $options = [];

            foreach ($field->getOptions() as $value => $label) {
                $options[] = ['value' => (string) $value, 'label' => $label];
            }
        }

        return $field->isRequired() ? $options : [['value' => '', 'label' => '—'], ...$options];
    }
}
