<?php

declare(strict_types=1);

namespace Fastleo\Files;

use Fastleo\Fastleo;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Alignment;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\Format;
use Intervention\Image\Interfaces\ImageInterface;
use LogicException;

/**
 * Варианты картинок для сайта: именованные размеры из `fastleo.images.presets`, WebP.
 *
 * Файлы лежат вне корня загрузок: `fastleo/variants/{размер}/{путь картинки}/{ширина}.webp`
 * и рядом `manifest.json` — какие ширины созданы и их высоты. Компонент
 * `<x-fastleo::picture>` читает описание из кеша (при промахе — с диска), поэтому на
 * показ не тратится запрос к S3. Водяной знак ставится только на варианты, оригинал чистый.
 *
 * ```php
 * // config/fastleo.php
 * 'presets' => ['card' => ['widths' => [400, 800], 'ratio' => '4:3', 'watermark' => true]],
 * ```
 */
final class Variants
{
    /** Папка вариантов на диске. */
    public const string ROOT = FileStore::SERVICE_ROOT.'/variants';

    /** Сколько хранится в кеше отметка «вариантов нет», чтобы не читать диск на каждом показе. */
    private const int MISSING_TTL = 600;

    /**
     * Имена размеров из конфига.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(array_filter(array_keys(config()->array('fastleo.images.presets', [])), is_string(...)));
    }

    /**
     * Размер по имени.
     *
     * @return array{widths: list<int>, ratio: float|null, watermark: bool}
     *
     * @throws LogicException такого размера нет в конфиге
     */
    public static function preset(string $name): array
    {
        $preset = config('fastleo.images.presets.'.$name);

        if (! is_array($preset) || str_contains($name, '.')) {
            throw new LogicException(__('fastleo::exceptions.image_preset_missing', ['preset' => $name], Fastleo::LOCALE));
        }

        return self::normalize($name, $preset);
    }

    /**
     * Папка вариантов одной картинки (или папки) для размера.
     */
    public static function folder(string $preset, string $path): string
    {
        return self::ROOT.'/'.$preset.'/'.$path;
    }

    /**
     * Создать варианты картинки для размера и вернуть описание: ширина → высота.
     * Ширины больше картинки не создаются (кроме одной — размером с картинку).
     * Не картинка, файла нет или он слишком большой — пустой массив, старые варианты удаляются.
     * Путь вне корня загрузок (испорченное значение в базе) — пустой массив, на диске ничего не трогается.
     *
     * @return array<int, int>
     */
    public static function generate(string $path, string $preset): array
    {
        $settings = self::preset($preset);

        if (PathGuard::entry($path) === null) {
            return [];
        }

        $disk = FileStore::disk();
        $folder = self::folder($preset, $path);
        $disk->deleteDirectory($folder);
        Cache::forget(self::cacheKey($path, $preset));

        $binary = FileTypes::isImage($path) && $disk->fileExists($path) ? (string) $disk->get($path) : '';

        if ($binary === '' || Images::problem($binary) !== null) {
            return [];
        }

        try {
            $image = Images::manager(animation: false)->decodeBinary($binary);
            $watermark = $settings['watermark'] ? self::watermark() : null;
            $sizes = [];

            if ($settings['ratio'] !== null) {
                [$width, $height] = self::fitRatio($image->width(), $image->height(), $settings['ratio']);
                $image->cover($width, $height);
            }

            foreach (self::widths($settings['widths'], $image->width()) as $width) {
                $variant = (clone $image)->scaleDown(width: $width);

                if ($watermark instanceof ImageInterface) {
                    self::placeWatermark($variant, $watermark);
                }

                $disk->put($folder.'/'.$width.'.webp', (string) $variant->encodeUsingFormat(Format::WEBP, quality: self::quality()));
                $sizes[$width] = $variant->height();
            }
        } catch (ImageException) {
            $disk->deleteDirectory($folder);

            return [];
        }

        $disk->put($folder.'/manifest.json', (string) json_encode($sizes));
        Cache::forever(self::cacheKey($path, $preset), $sizes);

        return $sizes;
    }

    /**
     * Созданные варианты картинки: ширина → высота; пустой массив — вариантов нет.
     *
     * @return array<int, int>
     */
    public static function sizes(string $path, string $preset): array
    {
        self::preset($preset);
        $key = self::cacheKey($path, $preset);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return self::sizesFrom($cached);
        }

        $manifest = self::folder($preset, $path).'/manifest.json';
        $disk = FileStore::disk();
        $sizes = $disk->fileExists($manifest) ? self::sizesFrom(json_decode((string) $disk->get($manifest), true)) : [];

        $sizes === [] ? Cache::put($key, [], self::MISSING_TTL) : Cache::forever($key, $sizes);

        return $sizes;
    }

    /**
     * Публичный адрес варианта.
     */
    public static function url(string $path, string $preset, int $width): string
    {
        return FileStore::url(self::folder($preset, $path).'/'.$width.'.webp');
    }

    /**
     * Удалить варианты файла или папки для всех размеров.
     *
     * @param  list<string>  $files  файлы удаляемой папки — их описания в кеше тоже сбрасываются
     */
    public static function delete(string $path, array $files = []): void
    {
        $disk = FileStore::disk();

        foreach (self::names() as $preset) {
            $disk->deleteDirectory(self::folder($preset, $path));

            foreach ([$path, ...$files] as $file) {
                Cache::forget(self::cacheKey($file, $preset));
            }
        }
    }

    /**
     * Ключ кеша описания вариантов.
     */
    private static function cacheKey(string $path, string $preset): string
    {
        return 'fastleo.variants.'.md5(config()->string('fastleo.files.disk', 'public').'|'.$preset.'|'.$path);
    }

    /**
     * Описание из кеша или файла → ширина → высота.
     *
     * @return array<int, int>
     */
    private static function sizesFrom(mixed $value): array
    {
        $sizes = [];

        foreach (is_array($value) ? $value : [] as $width => $height) {
            if (is_numeric($width) && is_int($height) && (int) $width > 0 && $height > 0) {
                $sizes[(int) $width] = $height;
            }
        }

        ksort($sizes);

        return $sizes;
    }

    /**
     * Ширины, которые стоит создать: не больше картинки; если все больше — одна, размером с картинку.
     *
     * @param  list<int>  $widths
     * @return list<int>
     */
    private static function widths(array $widths, int $imageWidth): array
    {
        $fitting = array_values(array_filter($widths, static fn (int $width): bool => $width <= $imageWidth));

        return $fitting === [] || count($fitting) < count($widths) ? array_values(array_unique([...$fitting, $imageWidth])) : $fitting;
    }

    /**
     * Наибольший прямоугольник нужной пропорции внутри картинки.
     *
     * @return array{0: int, 1: int}
     */
    private static function fitRatio(int $width, int $height, float $ratio): array
    {
        return $width / $height > $ratio
            ? [max(1, (int) round($height * $ratio)), $height]
            : [$width, max(1, (int) round($width / $ratio))];
    }

    /**
     * Картинка водяного знака из `fastleo.images.watermark.path` (путь на диске файлов) или null.
     */
    private static function watermark(): ?ImageInterface
    {
        $path = PathGuard::entry(config('fastleo.images.watermark.path'));
        $disk = FileStore::disk();

        if ($path === null || ! FileTypes::isImage($path) || ! $disk->fileExists($path)) {
            return null;
        }

        $binary = (string) $disk->get($path);

        return Images::problem($binary) === null ? Images::manager(animation: false)->decodeBinary($binary) : null;
    }

    /**
     * Поставить водяной знак: ширина — `size` % варианта, угол — `position`, прозрачность — `opacity` %.
     */
    private static function placeWatermark(ImageInterface $variant, ImageInterface $watermark): void
    {
        $size = max(1, min(100, config()->integer('fastleo.images.watermark.size', 20)));
        $opacity = max(0, min(100, config()->integer('fastleo.images.watermark.opacity', 50)));
        $position = Alignment::tryFrom(config()->string('fastleo.images.watermark.position', 'bottom-right')) ?? Alignment::BOTTOM_RIGHT;
        $mark = (clone $watermark)->scale(width: max(1, (int) round($variant->width() * $size / 100)));
        $margin = (int) round($variant->width() * 0.02);

        $variant->insert($mark, $margin, $margin, $position, $opacity / 100);
    }

    /**
     * Качество WebP вариантов (1–100).
     */
    private static function quality(): int
    {
        return max(1, min(100, config()->integer('fastleo.images.variant_quality', 80)));
    }

    /**
     * Размер из конфига → ширины, пропорция, знак.
     *
     * @param  array<array-key, mixed>  $preset
     * @return array{widths: list<int>, ratio: float|null, watermark: bool}
     *
     * @throws LogicException ширины не заданы или пропорция не в виде `ш:в`
     */
    private static function normalize(string $name, array $preset): array
    {
        $widths = array_values(array_unique(array_filter(
            array_map(static fn (mixed $width): int => is_int($width) ? $width : 0, (array) ($preset['widths'] ?? [])),
            static fn (int $width): bool => $width > 0 && $width <= 10_000,
        )));
        sort($widths);

        $ratio = $preset['ratio'] ?? null;

        if ($widths === [] || $ratio !== null && (! is_string($ratio) || preg_match('/^[1-9][0-9]{0,3}:[1-9][0-9]{0,3}$/', $ratio) !== 1)) {
            throw new LogicException(__('fastleo::exceptions.image_preset_invalid', ['preset' => $name], Fastleo::LOCALE));
        }

        [$ratioWidth, $ratioHeight] = is_string($ratio) ? array_map(intval(...), explode(':', $ratio)) : [0, 0];

        return [
            'widths' => $widths,
            'ratio' => is_string($ratio) ? $ratioWidth / $ratioHeight : null,
            'watermark' => ($preset['watermark'] ?? false) === true,
        ];
    }
}
