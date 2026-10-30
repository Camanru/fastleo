<?php

declare(strict_types=1);

namespace Fastleo\Mcp\Tools;

use Fastleo\Fastleo;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

/**
 * Разделы админки, доступные администратору.
 */
#[Description('Список разделов админки, к которым есть доступ: адрес (resource), название, модель, только ли для чтения, можно ли создавать записи.')]
#[IsReadOnly]
final class ListResources extends ResourceTool
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    #[Override]
    protected function run(Request $request): Response
    {
        $sections = [];

        foreach (array_keys(Fastleo::resources()->all()) as $slug) {
            $resource = Fastleo::resources()->find($slug);

            if ($resource instanceof Resource && $resource->isAvailableInMcp() && Authorization::allows('viewAny', $resource)) {
                $sections[] = [
                    'resource' => $resource::slug(),
                    'label' => $resource::label(),
                    'singular' => $resource::singular(),
                    'model' => $resource::model(),
                    'readonly' => $resource::isReadonly(),
                    'can_create' => Authorization::allows('create', $resource),
                ];
            }
        }

        return Response::json(['resources' => $sections]);
    }
}
