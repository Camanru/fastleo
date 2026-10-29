<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Дерево записей по колонке родителя: порядок обхода, глубина, предки, число детей.
 *
 * Строится одним лёгким запросом (ключ, родитель, порядок). Записи с несуществующим родителем
 * становятся корнями, записи в цикле (A → B → A) тоже выводятся — ничего не пропадает из списка.
 */
final class Tree
{
    /**
     * Ключи в порядке обхода.
     *
     * @var list<string>
     */
    private array $order = [];

    /**
     * Предки каждой записи (от корня к родителю).
     *
     * @var array<string, list<string>>
     */
    private array $ancestors = [];

    /**
     * Дети каждой записи в порядке соседей.
     *
     * @var array<string, list<string>>
     */
    private array $children = [];

    /**
     * @param  array<string, string|null>  $parents  ключ → ключ родителя, в порядке соседей
     */
    public function __construct(array $parents)
    {
        $roots = [];

        foreach ($parents as $key => $parent) {
            $key = (string) $key;

            if ($parent === null || $parent === $key || ! array_key_exists($parent, $parents)) {
                $roots[] = $key;
            } else {
                $this->children[$parent][] = $key;
            }
        }

        foreach ($roots as $root) {
            $this->walk($root);
        }

        // Записи в цикле не достижимы от корней — выводятся корнями в конце.
        foreach (array_keys($parents) as $key) {
            if (! isset($this->ancestors[(string) $key])) {
                $this->walk((string) $key);
            }
        }
    }

    /**
     * Дерево записей запроса (с его ограничениями и глобальными скоупами).
     *
     * ```php
     * $tree = Tree::load($resource->newQuery(), 'parent_id', 'sort');
     * ```
     *
     * @param  Builder<Model>  $query
     */
    public static function load(Builder $query, string $treeColumn, ?string $sortColumn): self
    {
        $model = $query->getModel();
        $key = $model->getKeyName();
        $query = (clone $query)->toBase();
        $query->orders = null;
        // Свой select() раздела не должен лишить дерево колонки родителя.
        $query->columns = null;
        $query->bindings['select'] = [];

        if ($sortColumn !== null) {
            $query->orderBy($model->qualifyColumn($sortColumn));
        }

        $rows = $query->orderBy($model->getQualifiedKeyName())
            ->get([$model->getQualifiedKeyName().' as '.$key, $model->qualifyColumn($treeColumn).' as '.$treeColumn]);

        $parents = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $parent = $row[$treeColumn] ?? null;
            $parents[self::scalar($row[$key] ?? null)] = $parent === null ? null : self::scalar($parent);
        }

        return new self($parents);
    }

    /**
     * Ключи в порядке обхода: всё дерево или потомки записи `$root` (без неё самой).
     *
     * @return list<string>
     */
    public function order(?string $root = null): array
    {
        if ($root === null) {
            return $this->order;
        }

        return array_values(array_filter($this->order, fn (string $key): bool => in_array($root, $this->ancestors[$key] ?? [], true)));
    }

    /**
     * Запись есть в дереве.
     */
    public function has(string $key): bool
    {
        return isset($this->ancestors[$key]);
    }

    /**
     * Глубина записи: 0 — корень (или первый уровень ветки `$root`).
     */
    public function depth(string $key, ?string $root = null): int
    {
        return count($this->ancestors($key, $root));
    }

    /**
     * Предки записи от корня к родителю (внутри ветки `$root` — без неё и выше).
     *
     * @return list<string>
     */
    public function ancestors(string $key, ?string $root = null): array
    {
        $ancestors = $this->ancestors[$key] ?? [];

        if ($root === null) {
            return $ancestors;
        }

        $position = array_search($root, $ancestors, true);

        return $position === false ? $ancestors : array_slice($ancestors, $position + 1);
    }

    /**
     * Сколько у записи детей.
     */
    public function childCount(string $key): int
    {
        return count($this->children[$key] ?? []);
    }

    /**
     * `$candidate` — сама запись `$key` или её потомок (переносить туда нельзя).
     */
    public function isSelfOrDescendant(string $key, string $candidate): bool
    {
        return $candidate === $key || in_array($key, $this->ancestors[$candidate] ?? [], true);
    }

    /**
     * Обход в глубину без рекурсии; уже пройденные записи пропускаются (защита от циклов).
     */
    private function walk(string $root): void
    {
        $stack = [[$root, []]];

        while ($stack !== []) {
            [$key, $ancestors] = array_pop($stack);

            if (isset($this->ancestors[$key])) {
                continue;
            }

            $this->ancestors[$key] = $ancestors;
            $this->order[] = $key;

            foreach (array_reverse($this->children[$key] ?? []) as $child) {
                $stack[] = [$child, [...$ancestors, $key]];
            }
        }
    }

    /**
     * Значение колонки строкой.
     */
    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
