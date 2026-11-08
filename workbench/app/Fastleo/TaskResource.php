<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Actions\SetToggle;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Date;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Number;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Filters\BelongsToFilter;
use Fastleo\Filters\BooleanFilter;
use Fastleo\Filters\DateRangeFilter;
use Fastleo\Filters\NumberRangeFilter;
use Fastleo\Filters\SelectFilter;
use Fastleo\Layout\Aside;
use Fastleo\Layout\Grid;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Enums\Priority;
use Workbench\App\Enums\TaskStatus;
use Workbench\App\Fastleo\Actions\CancelTask;
use Workbench\App\Fastleo\Actions\CompleteTasks;
use Workbench\App\Fastleo\Actions\DownloadTasks;
use Workbench\App\Fastleo\Actions\PostponeTasks;
use Workbench\App\Fastleo\Filters\ContainsText;
use Workbench\App\Models\Task;

/**
 * Витрина «Задачи»: всё о списке и действиях. Фильтры всех видов (список, связь, галочка, даты, числа, свой запрос
 * `using()`, свой тип фильтра, фильтр со значением по умолчанию), число записей на странице и варианты, порядок
 * по умолчанию, своя подпись кнопки создания, экспорт и импорт, правка в ячейке, быстрый переключатель, цвет строки,
 * число просроченных в меню (`badge()`), свои действия (с вопросом, с мини-формой, опасное с своим правом Policy,
 * скачивание файла) и готовые `SetToggle`. Права — TaskPolicy (выполненную нельзя удалить), `completed_at` ставит TaskObserver.
 */
final class TaskResource extends Resource
{
    protected static string $model = Task::class;

    protected static ?string $label = 'Задачи';

    protected static ?string $singular = 'Задача';

    protected static ?string $createLabel = 'Добавить задачу';

    protected static ?string $description = 'Список открывается с фильтром «Незакрытые» (значение по умолчанию). Красные строки — просроченные, жёлтые — срочные.';

    protected static string $icon = 'list-todo';

    protected static ?string $group = 'Витрина: список и действия';

    protected static int $position = 10;

    protected static ?int $perPage = 10;

    protected static ?array $perPageOptions = [10, 20, 50];

    protected static ?array $defaultOrder = ['due_on', 'asc'];

    protected static bool $exportable = true;

    protected static bool $importable = true;

    /**
     * Поля задачи; статус, приоритет и оценка правятся прямо в списке.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Задача')->required()->maxLength(160)->searchable()->sortable(),
            Textarea::make('description', 'Описание')->rows(4)->maxLength(2000)->searchable(),
            Grid::make(3, [
                Enum::make('status', 'Статус')->enum(TaskStatus::class)->badges()->required()->default(TaskStatus::New->value)->sortable()->inlineEditable(),
                Enum::make('priority', 'Приоритет')->enum(Priority::class)->badges()->required()->default(Priority::Normal->value)->sortable()->inlineEditable(),
                Number::make('estimate', 'Оценка, ч')->integer()->min(1)->max(999)->sortable()->inlineEditable(),
            ]),
            BelongsTo::make('supplier_id', 'Поставщик')->hideWhenEmpty(),
            Aside::make([
                Date::make('due_on', 'Срок')->sortable(),
                Toggle::make('is_urgent', 'Срочно')->sortable()->quickToggle(),
                DateTime::make('completed_at', 'Выполнена')->readonly()->hideOnCreate()->hideInList()->help('Ставит TaskObserver при статусе «Выполнена»'),
                DateTime::make('created_at', 'Создана')->onlyInList()->sortable(),
            ]),
        ];
    }

    /**
     * Фильтры всех видов.
     */
    public function filters(): array
    {
        return [
            SelectFilter::make('state', 'Показывать')
                ->options(['open' => 'Незакрытые', 'closed' => 'Закрытые'])
                ->using(fn (Builder $query, string $value) => $value === 'open'
                    ? $query->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])
                    : $query->whereIn('status', [TaskStatus::Done, TaskStatus::Cancelled]))
                ->default('open'),
            SelectFilter::make('status'),
            SelectFilter::make('priority'),
            BelongsToFilter::make('supplier_id'),
            BooleanFilter::make('is_urgent'),
            DateRangeFilter::make('due_on'),
            NumberRangeFilter::make('estimate', 'Оценка, ч'),
            SelectFilter::make('deadline', 'Срок')
                ->options(['overdue' => 'Просрочены', 'today' => 'Сегодня', 'week' => 'Ближайшие 7 дней'])
                ->using(fn (Builder $query, string $value) => match ($value) {
                    'overdue' => $query->whereDate('due_on', '<', today())->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled]),
                    'today' => $query->whereDate('due_on', today()),
                    default => $query->whereBetween('due_on', [today()->toDateString(), today()->addDays(7)->toDateString()]),
                }),
            BooleanFilter::make('has_description', 'С описанием')
                ->using(fn (Builder $query, bool $value) => $value ? $query->whereNotNull('description') : $query->whereNull('description')),
            ContainsText::make('description', 'В описании'),
        ];
    }

    /**
     * Свои действия и готовые «Срочно» / «Не срочно».
     */
    public function actions(): array
    {
        return [
            new CompleteTasks(),
            new PostponeTasks(),
            new CancelTask(),
            new DownloadTasks(),
            SetToggle::make('is_urgent', 'Отметить срочными', true)->icon('flame'),
            SetToggle::make('is_urgent', 'Снять срочность', false),
        ];
    }

    /**
     * Число просроченных задач рядом с пунктом меню.
     */
    public static function badge(): ?int
    {
        return Task::query()->overdue()->count() ?: null;
    }

    /**
     * Выполненная — зелёная, отменённая — серая, просроченная — красная, срочная — жёлтая.
     */
    public function rowTone(Model $record): ?string
    {
        if (! $record instanceof Task) {
            return null;
        }

        return match (true) {
            $record->status === TaskStatus::Done => 'success',
            $record->status === TaskStatus::Cancelled => 'neutral',
            $record->isOverdue() => 'danger',
            $record->is_urgent => 'warning',
            default => null,
        };
    }
}
