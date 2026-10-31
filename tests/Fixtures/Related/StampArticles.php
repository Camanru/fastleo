<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Related;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Fields\Text;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Действие с мини-формой, чью ошибку браузер сам не ловит (правило только на сервере): «код» должен быть 1234.
 */
final class StampArticles extends Action
{
    protected ?string $label = 'Поставить штамп';

    /**
     * Обязательный код с проверкой на сервере.
     */
    public function fields(): array
    {
        return [Text::make('code', 'Код')->required()->rules(['in:1234'])];
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    public function handle(Collection $records, array $data): ActionResult
    {
        $records->each(fn (Model $record) => $record->update(['title' => $record->getAttribute('title').' ✔']));

        return ActionResult::success('Штамп поставлен');
    }
}
