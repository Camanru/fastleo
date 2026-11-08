<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\SettingsVariants;

use Fastleo\Fields\Gallery;
use Fastleo\Fields\Image;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Text;
use Fastleo\Settings;
use Override;

/**
 * Настройки с картинками, для которых заданы размеры (`variants()`): главная картинка, галерея и слайды.
 */
final class BannerSettings extends Settings
{
    /**
     * Картинка, галерея и повторитель с подписью, картинкой и вложенным повторителем картинок.
     */
    #[Override]
    public function fields(): array
    {
        return [
            Image::make('hero', 'Главная')->directory('site')->variants('card'),
            Gallery::make('photos', 'Фото')->directory('site')->variants('card'),
            Repeater::make('slides', 'Слайды')->fields([
                Text::make('caption', 'Подпись'),
                Image::make('src', 'Картинка')->directory('site')->variants('card'),
                Repeater::make('thumbs', 'Миниатюры')->fields([Image::make('pic', 'Картинка')->directory('site')->variants('card')]),
            ]),
        ];
    }
}
