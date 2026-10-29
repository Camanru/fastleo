<?php

declare(strict_types=1);

namespace Fastleo\Mcp\Tools;

use Fastleo\Fastleo;
use Fastleo\Mcp\ToolFailed;
use Fastleo\Support\DatabaseErrors;
use Fastleo\Support\OperationCancelled;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Override;

/**
 * Удаление записи (в корзину, если у модели SoftDeletes). Только при запуске сервера с `--allow-delete`.
 */
#[Description('Удалить запись (в корзину, если у раздела есть корзина). Работает, только если сервер запущен с --allow-delete.')]
#[IsDestructive]
final class DeleteRecord extends ResourceTool
{
    /**
     * Инструмент виден агенту только при включённом удалении.
     */
    public function shouldRegister(): bool
    {
        return config()->boolean('fastleo.mcp.allow_delete');
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Адрес раздела из list-resources.')->required(),
            'id' => $schema->string()->description('Ключ записи.')->required(),
        ];
    }

    #[Override]
    protected function run(Request $request): Response
    {
        if (! $this->shouldRegister()) {
            throw new ToolFailed(__('fastleo::mcp.delete_disabled'));
        }

        $resource = $this->resource($request, 'viewAny');

        if ($resource::isReadonly()) {
            throw new ToolFailed(__('fastleo::mcp.readonly'));
        }

        $record = $this->record($request, $resource, 'delete');

        if ($resource->hasChildren($record)) {
            throw new ToolFailed(__('fastleo::mcp.has_children'));
        }

        try {
            DB::connection($record->getConnectionName())->transaction(fn (): null => $record->delete() === false ? throw new OperationCancelled : null);
        } catch (QueryException $exception) {
            throw new ToolFailed(DatabaseErrors::explain($exception), $exception->getCode(), $exception);
        } catch (OperationCancelled) {
            throw new ToolFailed(__('fastleo::resources.delete_cancelled', locale: Fastleo::LOCALE));
        }

        return Response::json(['message' => __('fastleo::mcp.deleted')]);
    }
}
