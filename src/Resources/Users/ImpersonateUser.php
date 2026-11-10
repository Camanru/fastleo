<?php

declare(strict_types=1);

namespace Fastleo\Resources\Users;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Auth\Impersonation;
use Fastleo\Fastleo;
use Fastleo\Support\Home;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Override;

/**
 * «Войти как»: владелец входит в панель под выбранным пользователем, чтобы проверить его права (`fastleo.users.impersonate`).
 * Доступно только владельцу, под пользователем с доступом в панель, который сам не владелец. Из MCP недоступно.
 */
final class ImpersonateUser extends Action
{
    protected ?string $key = 'impersonate';

    protected string $icon = 'log-in';

    protected bool $bulk = false;

    protected bool $mcp = false;

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        $target = $records->first();
        $owner = Fastleo::guard()->user();

        if (! $owner instanceof Authenticatable || ! $target instanceof Authenticatable || ! Impersonation::mayStart($owner, $target)) {
            abort(403);
        }

        // Входов под чужим именем бывает несколько в минуту, а не сотня: перебор кнопки — повод остановиться.
        $limit = 'fastleo-impersonate:'.Impersonation::identifierOf($owner);
        abort_if(RateLimiter::tooManyAttempts($limit, 10), 429, __('fastleo::users.impersonate_throttle'));
        RateLimiter::hit($limit, 60);

        Impersonation::start($owner, $target, route('fastleo.resources.index', $this->resource()::slug(), false));

        return ActionResult::redirect(Home::url(), __('fastleo::users.impersonate_started', ['name' => is_string($name = $target->getAttribute('name')) ? $name : '']));
    }

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::users.impersonate');
    }

    #[Override]
    public function getConfirm(): string
    {
        return __('fastleo::users.impersonate_confirm');
    }

    /**
     * Только владелец и только под пользователем, который сам не владелец.
     */
    #[Override]
    public function allows(Model $record): bool
    {
        return parent::allows($record) && $record instanceof Authenticatable && Impersonation::mayStart(Fastleo::guard()->user(), $record);
    }
}
