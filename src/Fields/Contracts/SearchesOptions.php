<?php

declare(strict_types=1);

namespace Fastleo\Fields\Contracts;

/**
 * Поле, варианты которого ищутся по серверу (`GET /fastleo/{resource}/options/{field}?q=&depends=`).
 */
interface SearchesOptions
{
    /**
     * Варианты, подходящие под строку поиска; `$dependency` — значение поля из `dependsOn()`.
     *
     * @return list<array{value: string, label: string}>
     */
    public function searchOptions(string $search, int $limit = 20, mixed $dependency = null): array;
}
