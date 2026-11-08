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
     * Место записи в порядке обхода (строится при первом обращении).
     *
     * @var array<string, int>|null
     */
    private ?array $position = null;

    /**
     * Корни верхнего уровня в порядке соседей (записи в цикле — в конце).
     *
     * @var list<string>
     */
    private array $roots = [];

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
            $this->roots[] = $root;
        }

        // Записи в цикле не достижимы от корней — выводятся корнями в конце.
        foreach (array_keys($parents) as $key) {
            if (! isset($this->ancestors[(string) $key])) {
                $this->walk((string) $key);
                $this->roots[] = (string) $key;
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
        // Порядок раздела с параметрами (`orderByRaw('… = ?', [...])`) уходит вместе со значениями.
        $query->bindings['order'] = [];
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
     * Записи одного уровня в порядке соседей: верхний уровень или прямые дети записи `$root`.
     *
     * ```php
     * $tree->roots();         // корни дерева
     * $tree->roots('5');      // дети записи 5
     * ```
     *
     * @return list<string>
     */
    public function roots(?string $root = null): array
    {
        if ($root === null) {
            return $this->roots;
        }

        // В цикле (A → B → A) предок записи — одновременно её ребёнок: показываем только настоящих потомков.
        $ancestors = $this->ancestors[$root] ?? [];

        return array_values(array_filter($this->children[$root] ?? [], fn (string $child): bool => $child !== $root && ! in_array($child, $ancestors, true)));
    }

    /**
     * Ключи в порядке обхода для заданных записей: каждая со всеми потомками, сразу за ней.
     *
     * ```php
     * $tree->subtrees($tree->roots());   // всё дерево, как order()
     * ```
     *
     * @param  list<string>  $keys  записи одного уровня в порядке обхода
     * @return list<string>
     */
    public function subtrees(array $keys): array
    {
        $order = [];
        $position = $this->position ??= array_flip($this->order);

        foreach ($keys as $key) {
            $index = $position[$key] ?? null;

            if ($index === null) {
                continue;
            }

            $order[] = $key;

            // Обход в глубину: потомки записи идут в порядке обхода подряд сразу за ней.
            for ($next = $index + 1; isset($this->order[$next]) && in_array($key, $this->ancestors[$this->order[$next]], true); $next++) {
                $order[] = $this->order[$next];
            }
        }

        return $order;
    }

    /**
     * Страница дерева: записи уровня со всеми потомками, но не больше `$limit` строк. Если не помещаются, у ветки
     * отрезается хвост (каждой записи остаётся место хотя бы для неё самой), а `truncated` равно true.
     *
     * ```php
     * ['keys' => $keys, 'truncated' => $cut] = $tree->page($tree->roots(), 1000);
     * ```
     *
     * @param  list<string>  $roots  записи одного уровня в порядке обхода
     * @return array{keys: list<string>, truncated: bool}
     */
    public function page(array $roots, int $limit): array
    {
        $keys = [];
        $truncated = false;

        foreach ($roots as $index => $root) {
            $subtree = $this->subtrees([$root]);
            $room = max(1, $limit - count($keys) - (count($roots) - $index - 1));

            if (count($subtree) > $room) {
                $subtree = array_slice($subtree, 0, $room);
                $truncated = true;
            }

            array_push($keys, ...$subtree);
        }

        return ['keys' => $keys, 'truncated' => $truncated];
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
