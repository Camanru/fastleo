<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Fastleo\Fastleo;
use Fastleo\Fields\Email;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Resource;
use Override;

/**
 * Встроенный раздел «Заявки»: заявки с формы сайта и с других сайтов по API. Непросмотренные выделены, их число стоит в меню;
 * о новой заявке пользователи панели узнают через `Fastleo::notifyAdmins()` — во включённых каналах (колокольчик, почта, боты:
 * `fastleo.notifications`). Через MCP и выгрузкой недоступен: в заявках телефоны и почты посторонних.
 *
 * Включается ключом `fastleo.leads.resource`: по умолчанию `null` — раздела нет; укажите класс этого ресурса или ваш наследник.
 *
 * ```php
 * // config/fastleo.php: 'leads' => ['resource' => App\Fastleo\LeadResource::class]
 * final class LeadResource extends \Fastleo\Leads\LeadResource
 * {
 *     protected static ?string $group = 'Продажи';
 * }
 * ```
 */
class LeadResource extends Resource
{
    protected static string $model = Lead::class;

    protected static ?string $slug = 'leads';

    protected static string $icon = 'inbox';

    protected static int $position = 30;

    protected static ?string $readColumn = 'read_at';

    protected static ?array $defaultOrder = ['created_at', 'desc'];

    protected static bool $exportable = false;

    protected static bool $mcp = false;

    #[Override]
    public static function label(): string
    {
        return static::$label ?? __('fastleo::leads.label', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function singular(): string
    {
        return static::$singular ?? __('fastleo::leads.singular', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function description(): ?string
    {
        return static::$description ?? __('fastleo::leads.description', [], Fastleo::LOCALE);
    }

    /**
     * Имя, телефон, почта, сообщение; откуда пришла заявка (страница, сайт, форма); статус.
     */
    #[Override]
    public function fields(): array
    {
        return [
            Text::make('name', __('fastleo::leads.name'))->required()->maxLength(100)->searchable()->sortable(),
            Tel::make('phone', __('fastleo::leads.phone'))->required()->maxLength(50)->searchable(),
            Email::make('email', __('fastleo::leads.email'))->maxLength(255)->searchable()->hideInList(),
            Textarea::make('message', __('fastleo::leads.message'))->rows(4)->hideInList(),
            Text::make('source', __('fastleo::leads.source'))->maxLength(500)->readonly()->hideInList(),
            Text::make('site', __('fastleo::leads.site'))->readonly()->searchable(),
            Text::make('form', __('fastleo::leads.form'))->readonly(),
            Enum::make('status', __('fastleo::leads.status'))->enum(LeadStatus::class)->badges()->default(LeadStatus::New->value)->sortable(),
        ];
    }
}
