<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Support\Html\VideoUrl;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Значение поля — ссылка на видео YouTube, Rutube или VK Видео, из которой получается адрес плеера.
 *
 * ```php
 * new VideoLinkRule;
 * ```
 */
final readonly class VideoLinkRule implements ValidationRule
{
    /**
     * Проверить значение.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (VideoUrl::embed($value) === null) {
            $fail(__('fastleo::resources.video_link', [], Fastleo::LOCALE));
        }
    }
}
