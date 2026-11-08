<?php

declare(strict_types=1);

namespace Fastleo\Files;

use Fastleo\Fastleo;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\Exceptions\MissingDependencyException;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

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
     * Наибольшее число кадров GIF: на каждый кадр декодер заводит объекты независимо от его размера, и GIF из ста тысяч кадров
     * 1×1 (2 МБ, всего 100 000 пикселей) исчерпывает память процесса. Живая анимация — до сотен кадров.
     */
    public const int MAX_GIF_FRAMES = 5_000;

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

        $frames = self::frames($binary);
        $pixels = $size[0] * $size[1] * $frames;

        if ($frames > self::MAX_GIF_FRAMES || $pixels > config()->integer('fastleo.files.max_pixels', 32_000_000)) {
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
     * @throws ImageException GD собран без этого формата или не умеет его кодировать
     */
    public static function encode(ImageInterface $image, string $extension): string
    {
        if (! resolve(ImageSupport::class)->canEncode($extension)) {
            throw new MissingDependencyException(__('fastleo::exceptions.image_cannot_encode', ['format' => $extension], Fastleo::LOCALE));
        }

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
     * Число кадров: у GIF — по блокам файла (картинки не декодируются: разбор 100 000 кадров 1×1, 2 МБ, съедал бы 100 МБ
     * памяти и десятки секунд), у остальных — 1. GIF, который не читается, считается по тому, что удалось прочитать
     * (не меньше одного кадра): отклонит его декодирование.
     */
    private static function frames(string $binary): int
    {
        if (! str_starts_with($binary, 'GIF8')) {
            return 1;
        }

        $position = 13 + self::paletteSize($binary, 10);
        $frames = 0;

        // Блок: «!» — расширение, «,» — кадр (9 байт описания, палитра, байт размера кода, данные), всё остальное («;» и мусор) — конец.
        while (in_array($block = $binary[$position] ?? '', ['!', ','], true)) {
            if ($block === ',') {
                $frames++;
                $position += 9 + self::paletteSize($binary, $position + 9) + 1;
            }

            $position = self::afterSubBlocks($binary, $position + ($block === '!' ? 2 : 1));
        }

        return max(1, $frames);
    }

    /**
     * Размер палитры GIF в байтах по байту флагов в позиции `$flags` (старший бит — палитра есть, младшие три — её размер).
     */
    private static function paletteSize(string $binary, int $flags): int
    {
        $byte = ord($binary[$flags] ?? "\0");

        return ($byte & 0x80) !== 0 ? 3 * (2 << ($byte & 7)) : 0;
    }

    /**
     * Позиция после цепочки блоков данных GIF (байт длины и столько же байт; цепочку завершает нулевая длина).
     */
    private static function afterSubBlocks(string $binary, int $position): int
    {
        while (($size = ord($binary[$position] ?? "\0")) > 0) {
            $position += $size + 1;
        }

        return $position + 1;
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
