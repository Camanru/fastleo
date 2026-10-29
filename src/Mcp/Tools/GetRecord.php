<?php

declare(strict_types=1);

namespace Fastleo\Mcp\Tools;

use Fastleo\Mcp\RecordData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

/**
 * Одна запись со всеми значениями формы.
 */
#[Description('Запись раздела со всеми значениями полей формы (то, что можно передать в update-record). Пароли и скрытые поля не отдаются.')]
#[IsReadOnly]
final class GetRecord extends ResourceTool
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
        ];
    }

    #[Override]
    protected function run(Request $request): Response
    {
        $resource = $this->resource($request, 'viewAny');

        return Response::json(RecordData::detail($resource, $this->record($request, $resource, 'view')));
    }
}
