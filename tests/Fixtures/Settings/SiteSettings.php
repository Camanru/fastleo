<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Settings;

use Fastleo\Fields\Email;
use Fastleo\Fields\KeyValue;
use Fastleo\Fields\Number;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Tags;
use Fastleo\Fields\Text;
use Fastleo\Fields\Toggle;
use Fastleo\Settings;
use Override;

/**
 * Настройки тестового сайта: разные типы полей, секрет и условие доступа.
 */
final class SiteSettings extends Settings
{
    protected static string $icon = 'globe';

    /**
     * Поля страницы.
     */
    #[Override]
    public function fields(): array
    {
        return [
            Text::make('title', 'Название сайта')->required()->default('Мой сайт'),
            Email::make('email', 'Почта'),
            Number::make('per_page', 'Записей на странице')->integer()->default(10),
            Toggle::make('maintenance', 'Закрыт'),
            Tags::make('keywords', 'Ключевые слова'),
            Repeater::make('phones', 'Телефоны')->fields([Text::make('number', 'Номер')->required()]),
            KeyValue::make('meta', 'Мета'),
            Text::make('secret', 'Секрет'),
            Text::make('other', 'Показывать при закрытии')->visibleWhen('maintenance', ['1']),
        ];
    }

    /**
     * Секрет шифруется в базе.
     */
    #[Override]
    public function encrypted(): array
    {
        return ['secret'];
    }
}
