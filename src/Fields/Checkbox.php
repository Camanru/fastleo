<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Support\Icons;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;
use Override;

/**
 * Галочка «да/нет». Колонка `boolean`, в модели — каст `'boolean'`.
 *
 * Снятая галочка сохраняется как `false`. В списке — значок.
 *
 * ```php
 * Checkbox::make('is_featured', 'Показывать на главной');
 * ```
 */
class Checkbox extends Field
{
    /** Значок в списке — кнопка: клик меняет значение без открытия формы. */
    protected bool $quickToggle = false;

    /**
     * Переключать значение прямо в списке одним кликом, без открытия формы.
     *
     * Сервер проверяет то же, что при сохранении формы: право `update` на запись, что поле видно
     * и не `readonly()`, правила поля; запись идёт через Eloquent (observers, журнал изменений).
     * Без права на запись и в разделе `$readonly` значок остаётся обычным.
     *
     * ```php
     * Toggle::make('menu', 'Показывать на сайте')->default(true)->quickToggle();
     * ```
     */
    public function quickToggle(bool $quickToggle = true): static
    {
        $this->quickToggle = $quickToggle;

        return $this;
    }

    /**
     * Включён быстрый переключатель в списке.
     */
    public function isQuickToggle(): bool
    {
        return $this->quickToggle;
    }

    /**
     * `true`/`false`; снятая галочка (нет значения) — `false`.
     */
    #[Override]
    public function dehydrate(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * «Да»/«Нет», 1/0, true/false, yes/no, «+»/«-» → `1`/`0`; пусто — `0`. Другое остаётся как есть (ошибка проверки).
     */
    #[Override]
    public function importValue(mixed $cell): ?string
    {
        $value = self::cellString($cell);

        return match ($value === null ? '' : mb_strtolower($value)) {
            '', '0', 'нет', 'false', 'no', '-' => '0',
            '1', 'да', 'true', 'yes', '+' => '1',
            default => $value,
        };
    }

    /**
     * Значение для формы — отмечено или нет.
     */
    #[Override]
    public function hydrate(Model $record): bool
    {
        return (bool) $record->getAttribute($this->name);
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['boolean'];
    }

    /**
     * Быстрый переключатель можно показать для записи: поле включено, видно в форме записи, не `readonly()`, условие `visibleWhen()` выполнено.
     * Право `update` на запись проверяет вызывающий.
     */
    public function quickToggleFor(Model $record): bool
    {
        return $this->quickToggle && $this->isWritable() && $this->isVisibleInForm($record) && $this->isConditionMet($record);
    }

    /**
     * Значок «да» (галочка) или «нет» (крестик) с подписью.
     */
    public function badge(bool $yes): HtmlString
    {
        $icon = Icons::render($yes ? 'check' : 'x', new ComponentAttributeBag([
            'class' => 'size-[15px] shrink-0',
        ]));
        $label = e(__($yes ? 'fastleo::resources.yes' : 'fastleo::resources.no', [], Fastleo::LOCALE));

        return new HtmlString('<span class="inline-flex items-center gap-1.5 '.($yes ? '' : 'text-ink-2').'">'.$icon->toHtml().$label.'</span>');
    }

    /**
     * Значение в списке и на просмотре — значок с подписью.
     */
    #[Override]
    protected function format(mixed $value): string|Htmlable|null
    {
        return $this->badge((bool) $value);
    }
}
