<?php

declare(strict_types=1);

namespace Fastleo\Actions;

use Fastleo\Fastleo;
use Fastleo\Fields\Checkbox;
use Fastleo\Fields\Field;
use Fastleo\Support\OperationCancelled;
use Fastleo\Support\RecordWriter;
use Fastleo\Support\ValidationMessages;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use LogicException;
use Override;

/**
 * Готовое действие «Показать» / «Скрыть»: ставит одно значение галочке (`Checkbox`, `Toggle`) у выбранных или одной записи.
 *
 * Записывает через Eloquent (`save()`: observers и журнал изменений работают) с проверкой правил поля, право — `update` по Policy.
 * Ключ в адресе — `set-{поле}-on` или `set-{поле}-off`, поэтому на одно поле можно повесить оба действия.
 *
 * ```php
 * public function actions(): array
 * {
 *     return [
 *         SetToggle::make('menu', 'Показать на сайте', true)->icon('eye'),
 *         SetToggle::make('menu', 'Скрыть с сайта', false)->icon('eye-off'),
 *     ];
 * }
 * ```
 */
final class SetToggle extends Action
{
    private function __construct(private readonly string $field, string $label, private readonly bool $value)
    {
        $this->label = $label;
        $this->key = 'set-'.Str::slug($field).'-'.($value ? 'on' : 'off');
        $this->icon = $value ? 'eye' : 'eye-off';
    }

    /**
     * Действие «поставить полю `$field` значение `$value`» с подписью кнопки `$label`.
     */
    public static function make(string $field, string $label, bool $value): self
    {
        return new self($field, $label, $value);
    }

    /**
     * Имя поля-галочки, которому действие ставит значение.
     */
    public function field(): string
    {
        return $this->field;
    }

    /**
     * У одной записи кнопка видна, только если меняет значение: у показанной записи — «Скрыть», у скрытой — «Показать».
     */
    #[Override]
    public function visibleFor(Model $record): bool
    {
        return (bool) $record->getAttribute($this->field) !== $this->value;
    }

    /**
     * Иконка кнопки (Lucide); по умолчанию `eye` для «включить» и `eye-off` для «выключить».
     */
    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Поставить значение; записи, у которых оно уже такое, не трогаются и не считаются.
     * Запись, где форма не дала бы менять поле (`readonly()`, скрыто для администратора, `visibleWhen()` не выполнено),
     * пропускается; значение проверяется правилами поля и пишется через `RecordWriter`, как у быстрого переключателя.
     *
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     *
     * @throws LogicException в ресурсе нет такой галочки
     * @throws QueryException ошибка базы данных
     */
    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        $field = $this->checkbox();
        $resource = $this->resource();
        $changed = 0;
        $locked = 0;
        $rejected = 0;
        $reason = '';

        foreach ($records as $record) {
            if (! in_array($field, $resource->writableFields($record), true) || ! $field->isConditionMet($record)) {
                $locked++;

                continue;
            }

            if ((bool) $record->getAttribute($this->field) === $this->value) {
                continue;
            }

            // Правила поля видят остальные поля записи (`required_if:other`, `different:other`), как в форме. Условие показа
            // (`visibleWhen`) ищет управляющее поле среди данных: оно берётся из записи, даже если его нельзя менять.
            $input = [...RecordWriter::currentValues($resource, $record), $field->name => Field::conditionValue($this->value)];
            $controlling = $field->getVisibleWhen()['field'] ?? null;

            if ($controlling !== null) {
                $input[$controlling] = $resource->controllingValue($controlling, $record, null);
            }

            $validator = validator($input, RecordWriter::rules($resource, $record, [$field]), ValidationMessages::all(), $resource->validationAttributes($record));

            if ($validator->fails()) {
                $rejected++;
                $reason = $reason !== '' ? $reason : $validator->errors()->first();

                continue;
            }

            try {
                RecordWriter::write($resource, $record, [$field], [$field->name => $validator->validated()[$field->name] ?? $this->value]);
            } catch (OperationCancelled) {
                continue;
            }

            if ($record->wasChanged($this->field)) {
                $changed++;
            }
        }

        $message = __('fastleo::actions.changed', ['count' => $changed], Fastleo::LOCALE);

        if ($locked > 0) {
            $message .= ' '.__('fastleo::actions.locked', ['count' => $locked], Fastleo::LOCALE);
        }

        if ($rejected > 0) {
            $message .= ' '.__('fastleo::actions.rejected', ['count' => $rejected, 'reason' => $reason], Fastleo::LOCALE);
        }

        return ActionResult::success($message);
    }

    /**
     * Поле-галочка ресурса.
     *
     * @throws LogicException поля нет в ресурсе или это не галочка
     */
    private function checkbox(): Checkbox
    {
        $field = $this->resource()->schema()->field($this->field);

        return $field instanceof Checkbox ? $field : throw new LogicException(__('fastleo::exceptions.set_toggle_field', ['field' => $this->field, 'resource' => $this->resource()::class], Fastleo::LOCALE));
    }
}
