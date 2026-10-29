<?php

declare(strict_types=1);

namespace Fastleo\Layout;

use Fastleo\Fields\Field;
use Override;

/**
 * Секция формы: заголовок, пояснение и поля; может сворачиваться.
 *
 * ```php
 * Section::make('Контакты', [Tel::make('phone'), Email::make('email')])
 *     ->description('Показываются в подвале сайта')
 *     ->collapsible();
 * ```
 */
final class Section extends Layout
{
    /** Пояснение под заголовком. */
    private ?string $description = null;

    /** Можно свернуть. */
    private bool $collapsible = false;

    /** Свёрнута при открытии формы. */
    private bool $collapsed = false;

    /**
     * @param  list<Field|Layout>  $children
     */
    public function __construct(public readonly string $label, array $children = [])
    {
        parent::__construct($children);
    }

    /**
     * Создать секцию.
     *
     * @param  list<Field|Layout>  $children
     */
    public static function make(string $label, array $children): self
    {
        return new self($label, $children);
    }

    /**
     * Пояснение под заголовком секции.
     */
    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Секцию можно свернуть кнопкой в заголовке.
     */
    public function collapsible(bool $collapsible = true): self
    {
        $this->collapsible = $collapsible;

        return $this;
    }

    /**
     * Секция свёрнута при открытии формы (и её можно развернуть).
     */
    public function collapsed(bool $collapsed = true): self
    {
        $this->collapsed = $collapsed;
        $this->collapsible = $this->collapsible || $collapsed;

        return $this;
    }

    /**
     * Пояснение или null.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Секцию можно свернуть.
     */
    public function isCollapsible(): bool
    {
        return $this->collapsible;
    }

    /**
     * Секция свёрнута при открытии.
     */
    public function isCollapsed(): bool
    {
        return $this->collapsed;
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::form.section';
    }
}
