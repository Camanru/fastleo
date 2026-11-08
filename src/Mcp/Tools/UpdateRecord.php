<?php

declare(strict_types=1);

namespace Fastleo\Mcp\Tools;

use Fastleo\Mcp\RecordData;
use Fastleo\Mcp\RecordSaver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Override;

/**
 * Изменение записи: меняются только присланные поля.
 */
#[Description('Изменить запись. В values передавайте только изменяемые поля; остальные не трогаются, переводы на других языках сохраняются. Но списки (вложенные записи HasMany/HasOne, Repeater, Blocks, Gallery, Tags, KeyValue, связи «многие ко многим», множественный Select) заменяются целиком: передавайте весь список, как вернул get-record; без --allow-delete пропавшие вложенные записи — ошибка.')]
#[IsDestructive]
#[IsIdempotent]
final class UpdateRecord extends ResourceTool
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Адрес раздела из list-resources.')->required(),
            'id' => $schema->string()->description('Ключ записи.')->required(),
            'values' => $schema->object()->description('Изменяемые поля: {"title": "Новый заголовок"}.')->required(),
        ];
    }

    #[Override]
    protected function run(Request $request): Response
    {
        $resource = $this->resource($request, 'viewAny');
        $record = $this->record($request, $resource, 'update');
        // Пустой values — не ошибка «обязательно» (пустой массив считается отсутствующим), а запись без изменений.
        $request->validate(['values' => ['present', 'array']]);
        $values = $request->get('values');
        $values = is_array($values) ? $values : [];

        // Нечего менять — запись не сохраняется: пустое сохранение запустило бы observers (`saved`) зря, а ответ
        // «сохранена» ввёл бы агента в заблуждение.
        if ($values === []) {
            return Response::json(['message' => __('fastleo::mcp.unchanged'), ...RecordData::detail($resource, $record->refresh())]);
        }

        $record = RecordSaver::save($resource, $record, $values);

        return Response::json(['message' => __('fastleo::mcp.saved'), ...RecordData::detail($resource, $record->refresh())]);
    }
}
