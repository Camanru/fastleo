<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

use Fastleo\Fastleo;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Markdown;
use Fastleo\Fields\Text;
use Fastleo\Fields\Url;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Встроенный раздел «Публикации»: посты в каналы Telegram и Max и журнал их отправки. Пост создаётся здесь
 * («Создать» — свободный пост) или кнопкой «Опубликовать» в разделе сайта; затем действие «Отправить».
 *
 * Пункт меню виден, когда есть включённый канал. Включается ключом `fastleo.publishing.publications`: класс
 * этого ресурса (по умолчанию), ваш наследник или `null`. Отправленную публикацию нельзя удалить из журнала
 * (пост остался бы в канале без следа) — сначала «Удалить из каналов».
 */
class PublicationResource extends Resource
{
    protected static string $model = Publication::class;

    protected static ?string $slug = 'publications';

    protected static string $icon = 'megaphone';

    protected static int $position = 70;

    protected static ?array $defaultOrder = ['id', 'desc'];

    protected static bool $exportable = false;

    /**
     * Зарегистрированный раздел публикаций (этот класс или наследник из конфига) или null, если он выключен.
     */
    public static function registered(): ?Resource
    {
        foreach (Fastleo::resources()->all() as $slug => $class) {
            if (is_a($class, self::class, true)) {
                return Fastleo::resources()->find($slug);
            }
        }

        return null;
    }

    #[Override]
    public static function label(): string
    {
        return static::$label ?? __('fastleo::publishing.label', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function singular(): string
    {
        return static::$singular ?? __('fastleo::publishing.singular', [], Fastleo::LOCALE);
    }

    #[Override]
    public static function description(): ?string
    {
        return static::$description ?? __('fastleo::publishing.description', [], Fastleo::LOCALE);
    }

    /**
     * Текст, картинки, кнопка, каналы; статус и доставки — для просмотра.
     */
    #[Override]
    public function fields(): array
    {
        return [
            Markdown::make('text', __('fastleo::publishing.text'))->required()->maxLength(20000)->rules([new FitsMessengers])->searchable()
                ->help(__('fastleo::publishing.text_help')),
            PublicationImages::make('images', __('fastleo::publishing.images'))->directory('publications')->max(Publication::MAX_IMAGES)->extensions(Publication::IMAGE_EXTENSIONS)
                ->help(__('fastleo::publishing.images_help')),
            Text::make('button_label', __('fastleo::publishing.button_label'))->maxLength(128)->rules(['required_with:button_url'])->hideInList(),
            Url::make('button_url', __('fastleo::publishing.button_url'))->maxLength(2048)->rules(['required_with:button_label'])->hideInList(),
            PublicationChannels::make('channel_ids', __('fastleo::publishing.channels'))->multiple()->required()
                ->options(fn (): array => Channel::options())
                ->help(__('fastleo::publishing.channels_help')),
            Text::make('status', __('fastleo::publishing.status'))->readonly()->hideOnCreate()
                ->formatUsing(fn (mixed $value): string => __('fastleo::publishing.status_'.(is_string($value) ? $value : 'draft'), [], Fastleo::LOCALE)),
            DateTime::make('scheduled_at', __('fastleo::publishing.scheduled_at'))->readonly()->hideOnCreate()->hideInList()
                ->hidden(fn (?Model $record): bool => ! $record instanceof Publication || $record->scheduled_at === null),
            PublicationSource::make('source', __('fastleo::publishing.source')),
            PublicationDeliveries::make('deliveries', __('fastleo::publishing.deliveries')),
        ];
    }

    #[Override]
    public function actions(): array
    {
        return [new SendPublication, new RetryPublication, new CancelPublication, new SendOverduePublication, new UpdateInChannels, new RemoveFromChannels];
    }

    /**
     * В меню — когда есть включённый канал (журнал по адресу открывается всегда).
     */
    #[Override]
    public static function inMenu(): bool
    {
        return Channel::anyEnabled();
    }

    /**
     * Нельзя удалить публикацию, пост которой есть (или мог появиться) в канале.
     */
    #[Override]
    public function can(string $ability, ?Model $record): bool
    {
        if ($record instanceof Publication && in_array($ability, ['delete', 'forceDelete'], true)) {
            return ! $record->hasPosts();
        }

        return true;
    }
}
