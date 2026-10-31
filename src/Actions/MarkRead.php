<?php

declare(strict_types=1);

namespace Fastleo\Actions;

use Fastleo\Fastleo;
use Fastleo\Support\ReturnUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Встроенные действия раздела с `$readColumn`: «Просмотрено» и «Не просмотрено» — у записи и у выбранных записей.
 * Нужно право `view`. Отметка ставится без событий модели и журнала изменений (`Resource::markRead()`).
 */
final class MarkRead extends Action
{
    protected string $ability = 'view';

    private function __construct(private readonly bool $read)
    {
        $this->key = $read ? 'mark-read' : 'mark-unread';
        $this->icon = $read ? 'mail-open' : 'mail';
    }

    /**
     * Действие «Просмотрено» (`true`) или «Не просмотрено» (`false`).
     */
    public static function make(bool $read): self
    {
        return new self($read);
    }

    #[Override]
    public function getLabel(): string
    {
        return __($this->read ? 'fastleo::actions.mark_read' : 'fastleo::actions.mark_unread', locale: Fastleo::LOCALE);
    }

    /**
     * У одной записи видна только кнопка, которая что-то меняет.
     */
    #[Override]
    public function visibleFor(Model $record): bool
    {
        return $this->resource()->isUnread($record) === $this->read;
    }

    #[Override]
    public function handle(Collection $records, array $data): ActionResult
    {
        $count = $this->resource()->markRead(array_values($records->modelKeys()), $this->read);
        $message = __('fastleo::actions.marked', ['count' => $count], Fastleo::LOCALE);
        $leave = $this->read ? null : $this->leaveRecordPage($records);

        return $leave === null ? ActionResult::success($message) : ActionResult::redirect($leave, $message);
    }

    /**
     * «Не просмотрено» со страницы самой записи: возврат на неё снова отметил бы запись, поэтому админка уходит
     * туда, откуда запись открыли (адрес возврата), или в список раздела. Из списка — null, остаёмся в нём.
     *
     * @param  Collection<int, Model>  $records
     */
    private function leaveRecordPage(Collection $records): ?string
    {
        $previous = url()->previous();
        $slug = $this->resource()::slug();

        foreach ($records as $record) {
            foreach (['fastleo.resources.show', 'fastleo.resources.edit'] as $route) {
                if (parse_url($previous, PHP_URL_PATH) === route($route, [$slug, $record->getKey()], false)) {
                    parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);

                    return ReturnUrl::sanitize($query['return'] ?? null) ?? route('fastleo.resources.index', $slug);
                }
            }
        }

        return null;
    }
}
