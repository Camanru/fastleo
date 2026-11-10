<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Fastleo\Auth\RoleMatrix;
use Fastleo\Fastleo;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Встроенный раздел «Доступы для сайтов»: ключи, с которыми другие сайты отправляют заявки по API (`POST /api/leads`). В карточке
 * есть готовая инструкция для разработчика сайта. Ключ даёт запись заявок, поэтому при включённых ролях или заданном
 * `Fastleo::resolveOwnerUsing()` раздел открыт только владельцу; без ролей и владельца — всем пользователям панели.
 * Через MCP, выгрузкой, импортом и общим поиском недоступен.
 *
 * Включается ключом `fastleo.leads.keys` (по умолчанию включён вместе с `fastleo.leads.resource`): `null` — приёма по API нет.
 */
class LeadApiKeyResource extends Resource
{
    protected static string $model = LeadApiKey::class;

    protected static ?string $slug = 'lead-api-keys';

    protected static string $icon = 'key-round';

    protected static int $position = 35;

    protected static ?array $defaultOrder = ['name', 'asc'];

    protected static bool $exportable = false;

    protected static bool $globallySearchable = false;

    protected static bool $mcp = false;

    #[Override]
    public static function label(): string
    {
        return static::$label ?? __('fastleo::leads.keys_label', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function singular(): string
    {
        return static::$singular ?? __('fastleo::leads.keys_singular', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function description(): ?string
    {
        return static::$description ?? __('fastleo::leads.keys_description', ['url' => url(config()->string('fastleo.leads.api_path', 'api/leads'))], Fastleo::LOCALE);
    }

    #[Override]
    public static function group(): ?string
    {
        return static::$group ?? __('fastleo::leads.keys_group', [], Fastleo::LOCALE);
    }

    /**
     * Название сайта, его адрес, включён ли; ключ, адрес приёма и инструкция (только чтение, у существующей записи).
     */
    #[Override]
    public function fields(): array
    {
        $isNew = fn (?Model $record): bool => ! $record instanceof Model || ! $record->exists;

        return [
            Text::make('name', __('fastleo::leads.key_name'))->required()->maxLength(100)->searchable()->sortable(),
            Text::make('site', __('fastleo::leads.key_site'))->maxLength(255)->help(__('fastleo::leads.key_site_help')),
            Toggle::make('is_active', __('fastleo::leads.key_active'))->default(true),
            DateTime::make('last_used_at', __('fastleo::leads.key_last_used'))->readonly()->hideOnCreate(),
            Textarea::make('note', __('fastleo::leads.key_note'))->rows(3)->hideInList(),
            Text::make('visible_token', __('fastleo::leads.key_token'))->readonly()->hideInList()->hidden($isNew),
            Text::make('intake_url', __('fastleo::leads.key_endpoint'))->readonly()->hideInList()->hidden($isNew),
            Textarea::make('instruction', __('fastleo::leads.key_instruction'))->readonly()->rows(22)->hideInList()->hidden($isNew)->help(__('fastleo::leads.key_instruction_help')),
        ];
    }

    #[Override]
    public function actions(): array
    {
        return [new RegenerateLeadApiKey()];
    }

    /**
     * Ключи видит и меняет только владелец, если сайт его знает (роли или `resolveOwnerUsing()`).
     */
    #[Override]
    public function can(string $ability, ?Model $record): bool
    {
        return RoleMatrix::allowsOwnerOnly();
    }
}
