<?php

declare(strict_types=1);

namespace Fastleo\Files;

/**
 * Чего не хватает PHP для работы с картинками: расширение GD (декодирование, WebP-варианты, миниатюры) с поддержкой
 * JPEG и WebP и расширение exif (поворот фото с телефона). Без GD любая загрузка картинки отклоняется как «не открывается
 * как картинка», и причину не видно; `fastleo:doctor` говорит о ней прямо.
 *
 * По умолчанию смотрит на настоящий PHP; чтобы проверить сайт «как на другом сервере», расширения и форматы GD можно
 * передать явно.
 *
 * ```php
 * resolve(ImageSupport::class)->missing(); // [] или ['gd'], ['webp', 'jpeg'], ['exif']
 * new ImageSupport(['gd'], IMG_JPG)->missing(); // ['webp', 'exif']
 * ```
 */
final readonly class ImageSupport
{
    /**
     * @param  list<string>|null  $extensions  загруженные расширения; `null` — настоящие
     * @param  int|null  $types  форматы GD (`IMG_*`, как у `imagetypes()`); `null` — настоящие
     */
    public function __construct(private ?array $extensions = null, private ?int $types = null) {}

    /**
     * GD умеет кодировать WebP: миниатюры менеджера и варианты картинок — WebP. Без него (GD собран без `--with-webp`)
     * `imagewebp()` не существует, и кодирование падает ошибкой PHP, а не исключением картинки.
     */
    public function canEncodeWebp(): bool
    {
        return array_intersect(['gd', 'webp'], $this->missing()) === [];
    }

    /**
     * GD умеет сохранить картинку с таким расширением: png и gif — с любой GD, jpg — собранной с JPEG, webp — с WebP.
     * Иначе `imagejpeg()` или `imagewebp()` не существует, и сохранение падает ошибкой PHP, а не исключением картинки.
     */
    public function canEncode(string $extension): bool
    {
        $missing = $this->missing();

        return match (strtolower($extension)) {
            'webp' => ! array_intersect(['gd', 'webp'], $missing),
            'jpg', 'jpeg' => ! array_intersect(['gd', 'jpeg'], $missing),
            default => ! in_array('gd', $missing, true),
        };
    }

    /**
     * Что отсутствует: `gd`, `webp` и `jpeg` (GD собран без формата), `exif`.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $loaded = array_map(strtolower(...), $this->extensions ?? get_loaded_extensions());
        $missing = [];

        if (! in_array('gd', $loaded, true)) {
            $missing[] = 'gd';
        } else {
            $types = $this->types ?? imagetypes();

            foreach (['webp' => IMG_WEBP, 'jpeg' => IMG_JPG] as $format => $flag) {
                if (($types & $flag) === 0) {
                    $missing[] = $format;
                }
            }
        }

        if (! in_array('exif', $loaded, true)) {
            $missing[] = 'exif';
        }

        return $missing;
    }
}
