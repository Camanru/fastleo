<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\Email;
use Fastleo\Fields\Image;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Toggle;
use Fastleo\Fields\Url;
use Fastleo\Layout\Section;
use Fastleo\Settings;
use Override;

/**
 * Демо-настройки сайта: контакты, счётчик и соцсети. На сайте — `fastleo_setting('contacts.phone')`.
 */
final class ContactsSettings extends Settings
{
    protected static ?string $label = 'Контакты';

    protected static string $icon = 'phone';

    protected static int $position = 10;

    /**
     * Поля страницы.
     */
    #[Override]
    public function fields(): array
    {
        return [
            Section::make('Связь', [
                Tel::make('phone', 'Телефон')->default('+7 900 000-00-00'),
                Email::make('lead_email', 'Почта для заявок')->required(),
                Textarea::make('working_hours', 'Часы работы')->help('Каждая строка — отдельная строка на сайте.'),
                Image::make('og_image', 'Картинка для соцсетей')->directory('settings'),
            ]),
            Section::make('Счётчики', [
                Text::make('metrika_id', 'Номер счётчика Метрики'),
                Text::make('api_key', 'Ключ внешнего сервиса')->help('Хранится в базе зашифрованным.'),
                Toggle::make('show_promo', 'Показывать акцию на сайте'),
            ]),
            Repeater::make('socials', 'Соцсети')->fields([
                Text::make('title', 'Название')->required(),
                Url::make('url', 'Ссылка')->required(),
            ]),
            KeyValue::make('extra', 'Дополнительно')->keyLabel('Параметр')->valueLabel('Значение'),
        ];
    }

    /**
     * Ключ внешнего сервиса шифруется.
     */
    #[Override]
    public function encrypted(): array
    {
        return ['api_key'];
    }
}
