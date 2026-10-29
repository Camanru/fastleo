<?php

declare(strict_types=1);

namespace Fastleo\Resources\Users;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Fastleo\Auth\TwoFactor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Сбросить двухфакторную защиту администратора, потерявшего телефон и резервные коды.
 * Доступно только для других администраторов с включённой защитой: свою защиту отключают в профиле, с паролем.
 */
final class ResetTwoFactor extends Action
{
    protected ?string $key = 'reset-two-factor';

    protected string $icon = 'shield-off';

    protected bool $bulk = false;

    protected bool $danger = true;

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        $twoFactor = resolve(TwoFactor::class);
        $count = 0;

        foreach ($records as $record) {
            if ($record instanceof Authenticatable && ! UserGuard::isCurrentUser($record)) {
                $twoFactor->disable($record);
                $count++;
            }
        }

        return ActionResult::success(__('fastleo::users.two_factor_reset', ['count' => $count]));
    }

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::users.reset_two_factor');
    }

    #[Override]
    public function getConfirm(): string
    {
        return __('fastleo::users.reset_two_factor_confirm');
    }

    /**
     * Только чужая запись с включённой защитой.
     */
    #[Override]
    public function allows(Model $record): bool
    {
        return parent::allows($record)
            && $record instanceof Authenticatable
            && ! UserGuard::isCurrentUser($record)
            && resolve(TwoFactor::class)->isEnabled($record);
    }
}
