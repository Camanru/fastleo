<?php

declare(strict_types=1);

namespace Fastleo\Mcp\Tools;

use Fastleo\Mcp\RecordData;
use Fastleo\Mcp\RecordSaver;
use Fastleo\Mcp\ToolFailed;
use Fastleo\Support\Authorization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Override;

/**
 * Новая запись раздела.
 */
#[Description('Создать запись. values — значения полей по именам из describe-resource; проверка и запись те же, что в форме админки. Ошибки проверки вернутся по полям.')]
#[IsDestructive(false)]
final class CreateRecord extends ResourceTool
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Адрес раздела из list-resources.')->required(),
            'values' => $schema->object()->description('Значения полей: {"title": "Заголовок", "is_published": true}.')->required(),
        ];
    }

    #[Override]
    protected function run(Request $request): Response
    {
        $resource = $this->resource($request, 'viewAny');

        if (! Authorization::allows('create', $resource)) {
            throw new ToolFailed(__('fastleo::mcp.forbidden', ['ability' => 'create']));
        }

        $request->validate(['values' => ['required', 'array']]);
        $values = $request->get('values');
        $record = RecordSaver::save($resource, null, is_array($values) ? $values : []);

        return Response::json(['message' => __('fastleo::mcp.saved'), ...RecordData::detail($resource, $record->refresh())]);
    }
}
