<?php

declare(strict_types=1);

namespace Fastleo\Support\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Адрес `<iframe src>` — только плееры видео по https: YouTube, Rutube, VK Видео.
 * Любой другой адрес снимается, и пустой iframe ничего не показывает.
 */
final class VideoFrameSanitizer implements AttributeSanitizerInterface
{
    /** Разрешённые начала адресов плееров. */
    public const array PLAYERS = [
        'https://www.youtube.com/embed/',
        'https://www.youtube-nocookie.com/embed/',
        'https://rutube.ru/play/embed/',
        'https://vk.com/video_ext.php?',
        'https://vkvideo.ru/video_ext.php?',
    ];

    /**
     * @return list<string>
     */
    public function getSupportedElements(): array
    {
        return ['iframe'];
    }

    /**
     * @return list<string>
     */
    public function getSupportedAttributes(): array
    {
        return ['src'];
    }

    /**
     * Адрес плеера из списка или null.
     */
    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $value = trim($value);

        foreach (self::PLAYERS as $player) {
            if (str_starts_with($value, $player) && preg_match('/[\s"\'<>\\\\]/', $value) !== 1) {
                return $value;
            }
        }

        return null;
    }
}
