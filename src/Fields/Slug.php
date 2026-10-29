<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Support\OptionRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Override;

/**
 * Адрес записи для сайта (`o-kompanii`): латиница в нижнем регистре, цифры и дефис.
 * С `from()` заполняется сам из другого поля, пока его не изменили вручную; если оставить пустым —
 * при сохранении строится из того поля с суффиксом `-2`, `-3`, когда адрес занят.
 * По умолчанию адрес уникален в таблице (вместе с записями в корзине).
 *
 * ```php
 * Slug::make('slug', 'Адрес')->from('title');
 * ```
 */
class Slug extends Text
{
    /** Формат адреса. */
    public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Поле, из которого строится адрес. */
    protected ?string $from = null;

    /** Адрес уникален в таблице. */
    protected bool $unique = true;

    /**
     * Строить адрес из другого поля формы.
     *
     * ```php
     * Slug::make('slug')->from('title');
     * ```
     */
    public function from(string $field): static
    {
        $this->from = $field;

        return $this;
    }

    /**
     * Проверять уникальность адреса в таблице (по умолчанию — да).
     *
     * ```php
     * Slug::make('slug')->unique(false)->rules(fn (?Model $record) => [...]);
     * ```
     */
    public function unique(bool $unique = true): static
    {
        $this->unique = $unique;

        return $this;
    }

    /**
     * Поле-источник адреса или null.
     */
    public function getFrom(): ?string
    {
        return $this->from;
    }

    /**
     * Адрес из текста: транслитерация с русского, всё лишнее — в дефисы.
     *
     * ```php
     * Slug::slugify('О компании'); // 'o-kompanii'
     * ```
     */
    public static function slugify(string $text): string
    {
        return Str::slug($text, '-', 'ru');
    }

    #[Override]
    public function getRules(?Model $record): array
    {
        $rules = parent::getRules($record);

        if ($this->unique) {
            $subject = $record ?? $this->model();

            // Адрес, который сам заполнил скрипт формы из источника, при занятости получит суффикс (`beforeSave`), а не ошибку.
            $rules[$this->name][] = new OptionRule(
                fn (mixed $value, array $data): bool => ! is_string($value)
                    || $this->isAutomatic($value, $data[$this->from ?? ''] ?? null)
                    || ! $this->isTaken($subject, $value),
                'unique',
            );
        }

        return $rules;
    }

    #[Override]
    protected function typeRules(): array
    {
        return [...parent::typeRules(), 'regex:'.self::PATTERN];
    }

    /**
     * Пустой адрес, а также адрес, равный автоматическому из `from()` (его мог заполнить скрипт формы),
     * строится из этого поля; занятый получает суффикс `-2`, `-3`…
     */
    #[Override]
    public function beforeSave(Model $record): void
    {
        $current = $record->getAttribute($this->name);

        if ($this->from === null) {
            return;
        }

        $source = method_exists($record, 'isTranslatableAttribute') && method_exists($record, 'getTranslations') && $record->isTranslatableAttribute($this->from)
            ? $record->getTranslations($this->from)
            : $record->getAttribute($this->from);
        $empty = ! is_string($current) || $current === '';

        if (! $empty && ! $this->isAutomatic($current, $source)) {
            return;
        }

        $base = trim(mb_substr(self::slugify($this->sourceText($source)), 0, $this->maxLength - 6), '-');

        if ($base === '') {
            return;
        }

        if (! $empty && (! $this->unique || ! $this->isTaken($record, $current))) {
            return;
        }

        $slug = $base;

        for ($suffix = 2; $this->unique && $this->isTaken($record, $slug); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        $record->fill([$this->name => $slug]);
    }

    /**
     * Адрес равен тому, что скрипт формы строит из источника: тот же текст через `slugify()`, обрезанный до длины поля.
     */
    private function isAutomatic(string $slug, mixed $source): bool
    {
        if ($this->from === null || $slug === '') {
            return false;
        }

        $automatic = mb_substr(self::slugify($this->sourceText($source)), 0, $this->maxLength);

        return $slug === $automatic || $slug === trim($automatic, '-');
    }

    /**
     * Текст источника: строка, а у многоязычного поля — текст основного языка.
     */
    private function sourceText(mixed $source): string
    {
        if (is_array($source)) {
            $source = $source[Fastleo::locales()[0]] ?? reset($source);
        }

        return is_scalar($source) ? (string) $source : '';
    }

    /**
     * Адрес занят другой записью (в том числе в корзине).
     */
    private function isTaken(Model $record, string $slug): bool
    {
        return $record->newQueryWithoutScopes()
            ->where($this->name, $slug)
            ->when($record->exists, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
            ->exists();
    }

    #[Override]
    public function view(): string
    {
        return 'fastleo::fields.slug';
    }

    #[Override]
    protected function canBeTranslated(): bool
    {
        return false;
    }
}
