<?php

declare(strict_types=1);

namespace Fastleo\Mcp\Tools;

use Fastleo\Auth\FastleoUser;
use Fastleo\Fastleo;
use Fastleo\Mcp\ToolFailed;
use Fastleo\Resource;
use Fastleo\Support\Authorization;
use Fastleo\Support\RecordKey;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Основа инструментов MCP: проверяет, что работает администратор админки, находит раздел и запись
 * по аргументам и сверяет права по Policy. Ошибки возвращаются агенту текстом, а не падением сервера.
 */
abstract class ResourceTool extends Tool
{
    /**
     * Выполнить инструмент от имени администратора.
     */
    final public function handle(Request $request): Response
    {
        app()->setLocale(Fastleo::LOCALE);

        try {
            $user = Fastleo::guard()->user();
            // Сервер живёт долго: доступ, снятый администратору после запуска, действует со следующего вызова.
            $user = $user instanceof Model ? $user->fresh() : $user;

            if (! $user instanceof FastleoUser || ! $user->canAccessFastleo()) {
                throw new ToolFailed(__('fastleo::mcp.no_admin'));
            }

            Fastleo::guard()->setUser($user);

            return $this->run($request);
        } catch (ToolFailed $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * Работа инструмента; ошибки пользователя — `ToolFailed`.
     *
     * @throws ToolFailed
     */
    abstract protected function run(Request $request): Response;

    /**
     * Раздел из аргумента `resource` (адрес) с проверкой права на него.
     *
     * @throws ToolFailed раздела нет или нет права
     */
    protected function resource(Request $request, string $ability): Resource
    {
        $slug = $request->get('resource');
        $resource = is_string($slug) ? Fastleo::resources()->find($slug) : null;

        if (! $resource instanceof Resource || ! $resource->isAvailableInMcp()) {
            $slugs = array_filter(array_keys(Fastleo::resources()->all()), fn (string $slug): bool => Fastleo::resources()->find($slug)?->isAvailableInMcp() === true);

            throw new ToolFailed(__('fastleo::mcp.unknown_resource', ['slug' => is_string($slug) ? $slug : '', 'list' => implode(', ', $slugs)]));
        }

        if (! Authorization::allows($ability, $resource)) {
            throw new ToolFailed(__('fastleo::mcp.forbidden', ['ability' => $ability]));
        }

        return $resource;
    }

    /**
     * Запись из аргумента `id` с проверкой права на неё.
     *
     * @throws ToolFailed записи нет или нет права
     */
    protected function record(Request $request, Resource $resource, string $ability): Model
    {
        $id = $request->get('id');
        $key = is_int($id) ? (string) $id : $id;
        $record = is_string($key) && RecordKey::valid($resource->newModel(), $key) ? $resource->newQuery()->whereKey($key)->first() : null;

        if (! $record instanceof Model) {
            throw new ToolFailed(__('fastleo::mcp.record_not_found'));
        }

        if (! Authorization::allows($ability, $resource, $record)) {
            throw new ToolFailed(__('fastleo::mcp.forbidden', ['ability' => $ability]));
        }

        return $record;
    }
}
