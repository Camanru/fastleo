<?php

declare(strict_types=1);

namespace Fastleo\Mcp\Tools;

use Fastleo\Auth\RoleMatrix;
use Fastleo\Auth\RolePermissions;
use Fastleo\Support\Authorization;
use Fastleo\Support\ResourceInspector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

/**
 * Описание раздела: поля, правила проверки, фильтры, действия и права администратора.
 */
#[Description('Описание раздела: поля (тип, обязательность, правила, связи), фильтры, действия и право создавать записи. Вызывайте перед create-record и update-record.')]
#[IsReadOnly]
final class DescribeResource extends ResourceTool
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return ['resource' => $schema->string()->description('Адрес раздела из list-resources.')->required()];
    }

    #[Override]
    protected function run(Request $request): Response
    {
        $resource = $this->resource($request, 'viewAny');

        return Response::json([
            ...new ResourceInspector()->resource($resource, forAdmin: true),
            'can' => ['create' => Authorization::allows('create', $resource)],
            ...(RoleMatrix::enabled() ? ['role_rights' => array_combine(RolePermissions::GROUPS, array_map(fn (string $group): bool => RoleMatrix::allows($group === 'view' ? 'viewAny' : $group, $resource::slug()), RolePermissions::GROUPS))] : []),
        ]);
    }
}
