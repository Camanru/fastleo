<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Fastleo\Actions\Action;
use Fastleo\Actions\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * «Перевыпустить ключ»: выдаёт доступу новый ключ, старый перестаёт работать сразу. Нужно, когда ключ утёк или сменился ключ
 * приложения (`APP_KEY`) и сохранённый ключ не расшифровать.
 */
final class RegenerateLeadApiKey extends Action
{
    protected ?string $key = 'regenerate';

    protected string $icon = 'key-round';

    protected bool $bulk = false;

    protected bool $danger = true;

    /**
     * @param  Collection<int, Model>  $records
     * @param  array<string, mixed>  $data
     */
    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        $count = 0;

        foreach ($records as $record) {
            if ($record instanceof LeadApiKey) {
                $record->issue()->save();
                $count++;
            }
        }

        return ActionResult::success(__('fastleo::leads.regenerated', ['count' => $count]));
    }

    #[Override]
    public function getLabel(): string
    {
        return __('fastleo::leads.regenerate');
    }

    #[Override]
    public function getConfirm(): string
    {
        return __('fastleo::leads.regenerate_confirm');
    }
}
