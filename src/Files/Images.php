<?php

declare(strict_types=1);

namespace Fastleo\Files;

use Intervention\Gif\Decoder as GifDecoder;
use Intervention\Gif\Exceptions\GifException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;

/**
 * Работа с картинками через Intervention Image (драйвер GD).
 *
 * Перед декодированием размер читается из заголовка (`getimagesizefromstring`):
 * картинка больше `fastleo.files.max_pixels` или такая, которой не хватит памяти PHP,
 * отклоняется, не открываясь, — иначе одна «пиксельная бомба» роняет процесс.
 * У анимированного GIF каждый кадр открывается во весь холст, поэтому пиксели
 * считаются по всем кадрам.
 */
final class Images
{
    /** Сколько байт памяти GD берёт на пиксель (с запасом). */
    private const int BYTES_PER_PIXEL = 5;

    /** Ширина и высота миниатюры файлового менеджера. */
    public const int THUMB_WIDTH = 320;

    public const int THUMB_HEIGHT = 240;

    /**
     * Ширина и высота по заголовку или null, если это не картинка.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function dimensions(string $binary): ?array
    {
        $size = @getimagesizefromstring($binary);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return [$size[0], $size[1]];
    }

    /**
     * Причина, по которой картинку нельзя открыть, или null.
     *
     * @return 'not_image'|'too_large'|'no_memory'|null
     */
    public static function problem(string $binary): ?string
    {
        $size = self::dimensions($binary);

        if ($size === null) {
            return 'not_image';
        }

        $pixels = $size[0] * $size[1] * self::frames($binary);

        if ($pixels > config()->integer('fastleo.files.max_pixels', 32_000_000)) {
            return 'too_large';
        }

        return self::fitsInMemory($pixels) ? null : 'no_memory';
    }

    /**
     * Пересохранить загруженную картинку: поворот по EXIF, без метаданных (в том числе
     * GPS с телефона), уменьшение до `fastleo.images.max_width`.
     *
     * @throws ImageException файл не декодируется — это не картинка
     */
    public static function normalize(string $binary, string $extension): string
    {
        $image = self::manager()->decodeBinary($binary);
        $maxWidth = config('fastleo.images.max_width');

        if (is_int($maxWidth) && $maxWidth > 0 && $image->width() > $maxWidth) {
            $image->scaleDown(width: $maxWidth);
        }

        return self::encode($image, $extension);
    }

    /**
     * Миниатюра для файлового менеджера: 320×240, обрезка по центру, WebP.
     *
     * @throws ImageException
     */
    public static function thumbnail(string $binary): string
    {
        $image = self::manager(animation: false)->decodeBinary($binary);
        $image->cover(self::THUMB_WIDTH, self::THUMB_HEIGHT);

        return (string) $image->encodeUsingFormat(Format::WEBP, quality: 80);
    }

    /**
     * Закодировать картинку в формат по расширению с качеством из конфига.
     *
     * @throws ImageException
     */
    public static function encode(ImageInterface $image, string $extension): string
    {
        return (string) $image->encodeUsingFileExtension($extension, quality: self::quality());
    }

    /**
     * Менеджер картинок на GD: поворот по EXIF включён.
     */
    public static function manager(bool $animation = true): ImageManager
    {
        return new ImageManager(Driver::class, autoOrientation: true, decodeAnimation: $animation);
    }

    /**
     * Качество JPEG и WebP из конфига (1–100).
     */
    private static function quality(): int
    {
        return max(1, min(100, config()->integer('fastleo.images.quality', 90)));
    }

    /**
     * Число кадров: у GIF — по блокам файла (без декодирования картинок), у остальных — 1.
     * GIF, который не читается, считается одним кадром: его отклонит декодирование.
     */
    private static function frames(string $binary): int
    {
        if (! str_starts_with($binary, 'GIF8')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b') ?: throw new RuntimeException('Поток php://memory недоступен.');

        try {
            fwrite($stream, $binary);

            return max(1, count(GifDecoder::decode($stream)->frames()));
        } catch (GifException) {
            return 1;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Хватит ли свободной памяти PHP на картинку из стольких пикселей.
     */
    private static function fitsInMemory(int $pixels): bool
    {
        $limit = UploadLimits::bytes(ini_get('memory_limit'));

        return $limit === 0 || $pixels * self::BYTES_PER_PIXEL < $limit - memory_get_usage();
    }
}
