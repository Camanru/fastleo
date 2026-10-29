<?php

declare(strict_types=1);

namespace Fastleo;

use Fastleo\Fields\Contracts\SearchesOptions;
use Fastleo\Fields\Field;
use Fastleo\Fields\Nested;
use Fastleo\Fields\Password;
use Fastleo\Fields\Select;
use Fastleo\Fields\Slug;
use Fastleo\Layout\Layout;
use Fastleo\Support\FormSchema;
use Fastleo\Support\SettingsRecord;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use LogicException;

/**
 * Страница настроек сайта на полях: телефон, адрес, почта для заявок, счётчики, соцсети.
 * Значения хранятся в таблице `fastleo_settings` (одна строка на страницу), читаются на сайте `fastleo_setting()`.
 *
 * Создаётся рядом с ресурсами (по умолчанию `app/Fastleo/`):
 *
 * ```php
 * namespace App\Fastleo;
 *
 * final class ContactsSettings extends \Fastleo\Settings
 * {
 *     protected static ?string $label = 'Контакты';
 *     protected static string $icon = 'phone';
 *
 *     public function fields(): array
 *     {
 *         return [
 *             Tel::make('phone', 'Телефон')->default('+7 900 000-00-00'),
 *             Email::make('lead_email', 'Почта для заявок')->required(),
 *             Text::make('metrika_id', 'Номер счётчика Метрики'),
 *         ];
 *     }
 * }
 *
 * // На сайте: fastleo_setting('contacts.phone'), fastleo_setting('contacts.lead_email', 'info@site.ru')
 * ```
 *
 * Адрес страницы (`slug`) — первая часть ключа: класс `ContactsSettings` → `contacts`.
 */
abstract class Settings
{
    /** Название страницы в меню и заголовке. По умолчанию — имя класса без «Settings». */
    protected static ?string $label = null;

    /** Иконка Lucide в меню. */
    protected static string $icon = 'settings';

    /** Группа меню; по умолчанию — «Настройки». */
    protected static ?string $group = null;

    /** Позиция в меню внутри группы. */
    protected static int $position = 0;

    /** Адрес страницы и первая часть ключа `fastleo_setting('slug.поле')`. По умолчанию — kebab имени класса без «Settings». */
    protected static ?string $slug = null;

    /** Поля, разложенные из fields() (считаются один раз на объект). */
    private ?FormSchema $schema = null;

    /**
     * Поля и раскладка страницы. Работают поля со значением в самой странице: Text, Textarea, Number, Select,
     * Toggle, Date, RichText, Image, File, Gallery, Tags, Repeater, KeyValue, Blocks… Нельзя: связи
     * (BelongsTo, HasMany…), Slug, Password и `translatable()`.
     *
     * @return list<Field|Layout>
     */
    abstract public function fields(): array;

    /**
     * Ключи полей, значения которых хранятся в базе зашифрованными (токены, пароли сервисов). В форму сохранённое
     * значение не выводится (поле пустое, пустое при сохранении оставляет прежнее), а `fastleo_setting()` отдаёт
     * расшифрованное значение.
     *
     * ```php
     * public function encrypted(): array
     * {
     *     return ['bot_token'];
     * }
     * ```
     *
     * @return list<string>
     */
    public function encrypted(): array
    {
        return [];
    }

    /**
     * Дополнительный Blade-шаблон под формой (кнопка проверки, подсказка). Получает `$settings`. По умолчанию — нет.
     */
    public function afterForm(): ?string
    {
        return null;
    }

    /**
     * Администратору можно открывать и менять страницу. По умолчанию — всем администраторам.
     */
    public function canAccess(?Authenticatable $user): bool
    {
        return true;
    }

    /**
     * Название страницы.
     */
    public static function label(): string
    {
        return static::$label ?? Str::headline(self::baseName());
    }

    /**
     * Иконка в меню.
     */
    public static function icon(): string
    {
        return static::$icon;
    }

    /**
     * Группа меню.
     */
    public static function group(): ?string
    {
        return static::$group ?? __('fastleo::settings.group', [], Fastleo::LOCALE);
    }

    /**
     * Позиция в меню.
     */
    public static function position(): int
    {
        return static::$position;
    }

    /**
     * Адрес страницы и первая часть ключа настройки.
     */
    public static function slug(): string
    {
        return static::$slug ?? Str::kebab(self::baseName());
    }

    /**
     * Поля страницы, привязанные к модели настроек; запрещённые поля — ошибка описания.
     *
     * @throws LogicException поле, которое на странице настроек не работает
     */
    public function schema(): FormSchema
    {
        if ($this->schema instanceof FormSchema) {
            return $this->schema;
        }

        $schema = new FormSchema($this->fields(), SettingsRecord::class);

        foreach ($schema->fields() as $field) {
            if ($field instanceof Nested || ($field instanceof SearchesOptions && ! $field instanceof Select) || ! $field->writesAttribute() || $field instanceof Slug || $field instanceof Password || $field->isTranslatable() || $field->getDependsOn() !== null) {
                throw new LogicException(__('fastleo::exceptions.settings_field', ['field' => $field->name, 'type' => class_basename($field), 'settings' => static::class], Fastleo::LOCALE));
            }

            // Значение страницы собирается из формы целиком: нередактируемое поле стёрлось бы.
            if ($field->hasRecordVisibility() || $field->isReadonly() || $field->isDisabled() || $field->isHidden()) {
                throw new LogicException(__('fastleo::exceptions.settings_field_locked', ['field' => $field->name, 'settings' => static::class], Fastleo::LOCALE));
            }
        }

        return $this->schema = $schema;
    }

    /**
     * Имена всех полей страницы.
     *
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_map(fn (Field $field): string => $field->name, $this->schema()->fields());
    }

    /**
     * Имя класса без слова «Settings».
     */
    private static function baseName(): string
    {
        $name = class_basename(static::class);

        return Str::replaceEnd('Settings', '', $name) ?: $name;
    }
}
