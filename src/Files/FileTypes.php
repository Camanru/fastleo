<?php

declare(strict_types=1);

namespace Fastleo\Files;

/**
 * Какие файлы можно загружать и какие из них — картинки.
 *
 * Списки берутся из `fastleo.files.extensions` и `fastleo.files.images`, но исполняемые
 * и активные форматы (php, html, svg, js…) запрещены всегда: сервер или браузер может
 * выполнить их как код. Картинками считаются только форматы, которые пакет умеет
 * проверить и пересохранить (png, jpg, jpeg, gif, webp).
 */
final class FileTypes
{
    /** Расширения, которые нельзя разрешить даже в конфиге. */
    public const array FORBIDDEN = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phtml', 'phar', 'phps', 'inc',
        'html', 'htm', 'xhtml', 'shtml', 'xml', 'xsl', 'svg', 'svgz', 'js', 'mjs',
        'htaccess', 'htpasswd', 'ini', 'user', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp', 'exe', 'swf',
    ];

    /** Форматы картинок, которые пакет проверяет и пересохраняет. */
    public const array IMAGE_FORMATS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /**
     * Разрешённые расширения в нижнем регистре.
     *
     * ```php
     * FileTypes::allowed(); // ['png', 'jpg', ..., 'zip']
     * ```
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $extensions = array_map(
            static fn (mixed $extension): string => is_string($extension) ? strtolower(trim($extension, '. ')) : '',
            config()->array('fastleo.files.extensions', []),
        );

        return array_values(array_unique(array_filter(
            $extensions,
            static fn (string $extension): bool => $extension !== '' && ! in_array($extension, self::FORBIDDEN, true),
        )));
    }

    /**
     * Расширения картинок среди разрешённых.
     *
     * @return list<string>
     */
    public static function images(): array
    {
        $images = array_map(
            static fn (mixed $extension): string => is_string($extension) ? strtolower(trim($extension, '. ')) : '',
            config()->array('fastleo.files.images', []),
        );

        return array_values(array_intersect(self::allowed(), self::IMAGE_FORMATS, $images));
    }

    /**
     * Расширение пути в нижнем регистре: `uploads/Photo.JPG` → `jpg`.
     */
    public static function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Можно ли хранить файл с таким путём.
     */
    public static function isAllowed(string $path): bool
    {
        return in_array(self::extension($path), self::allowed(), true);
    }

    /**
     * Картинка ли это по расширению.
     */
    public static function isImage(string $path): bool
    {
        return in_array(self::extension($path), self::images(), true);
    }
}
