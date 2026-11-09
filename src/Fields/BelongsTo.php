<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Fields\Concerns\QueriesRelatedRecords;
use Fastleo\Fields\Contracts\SearchesOptions;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\OptionRule;
use Fastleo\Support\RecordKey;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as BelongsToRelation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use Override;

/**
 * Связь «принадлежит»: запись ссылается на запись другой таблицы (`category_id` → категория).
 * Имя поля — колонка внешнего ключа; связь берётся из метода модели (`category()` для `category_id`).
 * Список вариантов с поиском; если вариантов больше `fastleo.relations.preload`, поиск идёт по серверу.
 *
 * ```php
 * BelongsTo::make('category_id', 'Категория')->required()->sortable();
 * BelongsTo::make('brand_id', 'Бренд')->titleColumn('short_name')->query(fn (Builder $q) => $q->where('is_active', true));
 * ```
 */
class BelongsTo extends Field implements SearchesOptions
{
    use QueriesRelatedRecords;

    /**
     * Связь модели (запоминается).
     *
     * @var BelongsToRelation<Model, Model>|null
     */
    private ?BelongsToRelation $relationInstance = null;

    /** Право на выбранную запись, без которого связь не сохраняется (`mustAllow()`). */
    private ?string $requiredAbility = null;

    /**
     * Метод связи: заданный `relation()` или имя поля без `_id` (`category_id` → `category`).
     */
    #[Override]
    public function getRelationName(): string
    {
        return $this->relationName ?? Str::camel(Str::beforeLast($this->name, '_id'));
    }

    /**
     * Связь `BelongsTo` модели ресурса.
     *
     * @return BelongsToRelation<Model, Model>
     *
     * @throws LogicException у модели нет такого метода связи или внешний ключ связи — другая колонка
     */
    public function relationInstance(): BelongsToRelation
    {
        if ($this->relationInstance instanceof BelongsToRelation) {
            return $this->relationInstance;
        }

        $model = $this->model();
        $method = $this->getRelationName();
        $relation = method_exists($model, $method) ? $model->{$method}() : null;

        if (! $relation instanceof BelongsToRelation) {
            throw new LogicException(__('fastleo::exceptions.relation_missing', [
                'field' => $this->name, 'model' => $model::class, 'method' => $method, 'type' => 'BelongsTo',
            ], Fastleo::LOCALE));
        }

        if ($relation->getForeignKeyName() !== $this->name) {
            throw new LogicException(__('fastleo::exceptions.relation_foreign_key', [
                'field' => $this->name, 'method' => $method, 'key' => $relation->getForeignKeyName(),
            ], Fastleo::LOCALE));
        }

        return $this->relationInstance = $relation;
    }

    /**
     * Связь идёт по первичному ключу связанной модели. Значения вариантов, проверка и запись работают с первичным ключом:
     * связь по другому ключу (`belongsTo(Country::class, 'country_code', 'code')`) сбросила бы выбранное значение в NULL при
     * сохранении. Показ значения (список, страница просмотра) этого не требует: Eloquent сам находит запись по ключу связи.
     *
     * @throws LogicException связь идёт не по первичному ключу связанной модели
     */
    #[Override]
    public function assertKeyedByPrimaryKey(): void
    {
        if (! $this->isKeyedByPrimaryKey()) {
            $relation = $this->relationInstance();

            throw new LogicException(__('fastleo::exceptions.relation_owner_key', [
                'field' => $this->name, 'method' => $this->getRelationName(), 'key' => $relation->getOwnerKeyName(), 'primary' => $relation->getRelated()->getKeyName(),
            ], Fastleo::LOCALE));
        }
    }

    /**
     * Колонка поля хранит первичный ключ связанной модели (а не другой её ключ, например `code`).
     */
    public function isKeyedByPrimaryKey(): bool
    {
        $relation = $this->relationInstance();

        return $relation->getOwnerKeyName() === $relation->getRelated()->getKeyName();
    }

    #[Override]
    public function relatedModel(): Model
    {
        return $this->relationInstance()->getRelated();
    }

    /**
     * Связь подгружается в список одним запросом.
     *
     * @return list<string>
     */
    #[Override]
    public function eagerLoad(): array
    {
        return [$this->getRelationName()];
    }

    #[Override]
    public function hydrate(Model $record): ?string
    {
        $value = $record->getAttribute($this->name);

        return is_scalar($value) ? (string) $value : null;
    }

    #[Override]
    public function dehydrate(mixed $value): mixed
    {
        $this->assertKeyedByPrimaryKey();

        $value = parent::dehydrate($value);

        return is_numeric($value) && $this->relatedModel()->getKeyType() === 'int' ? (int) $value : $value;
    }

    /**
     * Правила; сохранённое значение записи проходит проверку, даже если связанная запись скрыта.
     * В блоке `HasMany` сохранённым считается и ключ, уже стоящий в этом же блоке (`$keptFor`).
     *
     * @param  (Closure(string, array<array-key, mixed>): list<string>)|null  $keptFor  по имени проверяемого поля и данным формы
     * @return array<string, list<mixed>>
     */
    #[Override]
    public function getRules(?Model $record, ?Closure $keptFor = null): array
    {
        $rules = parent::getRules($record);
        $rules[$this->name] = array_map(fn (mixed $rule): mixed => $rule instanceof OptionRule ? $this->optionRules($record, $keptFor)[1] : $rule, $rules[$this->name]);

        if ($this->requiredAbility !== null) {
            $rules[$this->name][] = $this->abilityRule($record, $this->requiredAbility, $keptFor);
        }

        return $rules;
    }

    /**
     * Связь сохраняется, только если администратору разрешено действие над выбранной записью (по Policy её модели и `can()`
     * её раздела). Так запись нельзя привязать к чужому или закрытому родителю: запчасть — к чужой заявке, комментарий — к закрытой
     * теме. Уже сохранённое значение при правке не проверяется (запись остаётся там, где была); чтобы запретить перенос,
     * добавьте `hideOnEdit()`. Проверка идёт на сервере: список вариантов она не сужает — для этого есть `query()`.
     *
     * ```php
     * BelongsTo::make('offer_id', 'Заявка')->required()->mustAllow('update')->hideOnEdit();
     * ```
     *
     * @param  string  $ability  действие над выбранной записью: `update` (по умолчанию), `view`, `delete`…
     */
    public function mustAllow(string $ability = 'update'): static
    {
        $this->requiredAbility = $ability;

        return $this;
    }

    /**
     * Правило «действие над выбранной записью разрешено». Пустое, сохранённое (в записи или в этом же блоке `HasMany` —
     * `$keptFor`) и несуществующее значение пропускает: о них говорят правила вариантов.
     *
     * @param  (Closure(string, array<array-key, mixed>): list<string>)|null  $keptFor
     */
    private function abilityRule(?Model $record, string $ability, ?Closure $keptFor = null): OptionRule
    {
        $saved = $this->savedKeys($record);

        return new OptionRule(function (mixed $value, array $data, string $attribute) use ($ability, $saved, $keptFor): bool {
            $kept = [...$saved, ...($keptFor instanceof Closure ? $keptFor($attribute, $data) : [])];

            if (! is_scalar($value) || $this->isEmptyValue($value) || in_array((string) $value, $kept, true) || RecordKey::strict($this->relatedModel(), (string) $value) === false) {
                return true;
            }

            $related = $this->baseOptionsQuery()->whereKey($value)->first();

            if (! $related instanceof Model) {
                return true;
            }

            $resource = Fastleo::resources()->forModel($related::class);

            if ($resource instanceof Resource) {
                return Authorization::allows($ability, $resource, $related);
            }

            return Gate::getPolicyFor($related) === null || Gate::forUser(Fastleo::guard()->user())->allows($ability, $related);
        }, 'parent_denied', 'allows:'.$ability);
    }

    #[Override]
    protected function typeRules(): array
    {
        return $this->optionRules();
    }

    /**
     * Подпись связанной записи; запись не найдена — значение ключа как есть.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        if ($this->formatUsing instanceof Closure) {
            return parent::display($record);
        }

        if ($this->isEmptyValue($record->getAttribute($this->name))) {
            return null;
        }

        $related = $record->getRelationValue($this->getRelationName());

        return $related instanceof Model ? $this->titleOf($related) : $this->format($record->getAttribute($this->name));
    }

    /**
     * Адрес страницы связанной записи в её разделе (для ссылки в списке) или null: записи нет, у модели нет раздела
     * или администратору не разрешён просмотр (Policy `view` и `Resource::can()`).
     *
     * ```php
     * BelongsTo::make('category_id')->linkUrl($article);   // /fastleo/categories/5
     * ```
     */
    public function linkUrl(Model $record): ?string
    {
        $related = $record->getRelationValue($this->getRelationName());

        if (! $related instanceof Model) {
            return null;
        }

        // Ссылка ведёт в первый раздел модели, который администратору открыт (разделов может быть несколько).
        foreach (Fastleo::resources()->allForModel($related::class) as $resource) {
            $url = $resource->viewUrl($related);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Ключ связанной записи; значение `emptyValue()` — пустая ячейка.
     */
    #[Override]
    public function exportValue(Model $record): string|int|float|null
    {
        return $this->isEmptyValue($record->getAttribute($this->name)) ? null : parent::exportValue($record);
    }

    #[Override]
    protected function packageView(): string
    {
        return 'fastleo::fields.combobox';
    }

    /**
     * Список выбирает одну запись.
     */
    #[Override]
    public function isMultiple(): bool
    {
        return false;
    }
}
