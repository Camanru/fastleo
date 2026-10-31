<?php

declare(strict_types=1);

namespace Fastleo\Mcp\Tools;

use Fastleo\Actions\Action;
use Fastleo\Actions\DeletesRecords;
use Fastleo\Mcp\ToolFailed;
use Fastleo\Support\ActionInput;
use Fastleo\Support\DatabaseErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Override;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Запуск действия раздела над выбранными записями.
 */
#[Description('Запустить действие раздела (ключи — в describe-resource, actions) над записями ids. Записи без права на действие пропускаются. Встроенные удаления работают только с --allow-delete.')]
#[IsDestructive]
final class RunAction extends ResourceTool
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Адрес раздела из list-resources.')->required(),
            'action' => $schema->string()->description('Ключ действия из describe-resource.')->required(),
            'ids' => $schema->array()->items($schema->string())->description('Ключи записей.')->required(),
            'data' => $schema->object()->description('Значения мини-формы действия, если она есть.'),
        ];
    }

    #[Override]
    protected function run(Request $request): Response
    {
        $resource = $this->resource($request, 'viewAny');
        $key = $request->get('action');
        $actions = $resource->listActions();
        $action = is_string($key) ? ($actions[$key] ?? null) : null;

        if (! $action instanceof Action) {
            throw new ToolFailed(__('fastleo::mcp.action_unknown', ['key' => is_string($key) ? $key : '', 'list' => implode(', ', array_keys($actions))]));
        }

        if (! $action->isAvailableInMcp()) {
            throw new ToolFailed(__('fastleo::mcp.action_not_in_mcp'));
        }

        if ($action->isForTrash()) {
            throw new ToolFailed(__('fastleo::mcp.action_for_trash'));
        }

        if ($action instanceof DeletesRecords && ! config()->boolean('fastleo.mcp.allow_delete')) {
            throw new ToolFailed(__('fastleo::mcp.delete_disabled'));
        }

        $ids = $request->get('ids');
        $ids = ActionInput::ids($resource, is_array($ids) ? array_map(fn (mixed $id): mixed => is_int($id) ? (string) $id : $id, $ids) : null);

        if ($ids === null) {
            throw new ToolFailed(__('fastleo::mcp.action_ids'));
        }

        if (! $action->isBulk() && count($ids) > 1) {
            throw new ToolFailed(__('fastleo::mcp.action_bulk'));
        }

        $records = $resource->newQuery()->whereKey($ids)->get();
        $allowed = $records->filter(fn (Model $record): bool => $action->allows($record))->values();

        if ($allowed->isEmpty()) {
            throw new ToolFailed(__('fastleo::mcp.action_no_records'));
        }

        $input = $request->get('data');
        $validator = ActionInput::validator($action, ['data' => is_array($input) ? $input : []]);

        if ($validator->fails()) {
            throw new ToolFailed(__('fastleo::mcp.validation', ['errors' => json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]));
        }

        try {
            $result = $action->handle($allowed, ActionInput::values($action, $validator));
        } catch (QueryException $exception) {
            throw new ToolFailed(DatabaseErrors::explain($exception), (int) $exception->getCode(), previous: $exception);
        }

        $message = $result->message ?? __('fastleo::mcp.action_done');

        // Действие уже выполнено: ответ-файл через MCP не передаётся, но это не ошибка.
        if ($result->response instanceof HttpResponse) {
            $message .= ' '.__('fastleo::mcp.action_response');
        }

        return $result->type === 'error' ? Response::error($message) : Response::json(['message' => $message, 'processed' => $allowed->count(), 'skipped' => $records->count() - $allowed->count()]);
    }
}
