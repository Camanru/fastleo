<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Files\FileName;
use Fastleo\Files\FileStore;
use Fastleo\Files\FileTypes;
use Fastleo\Files\PathGuard;
use Fastleo\Support\FilePathRule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use LogicException;
use Override;

/**
 * Основа полей с файлами из менеджера (Image, File, Gallery). Значение — путь
 * относительно диска `fastleo.files.disk`: `uploads/doctors/ivanov.jpg`.
 *
 * В форме: «Загрузить» (файл сразу попадает в менеджер, в папку `directory()`)
 * и «Выбрать из файлов» (окно менеджера). Путь из формы проверяется: внутри корня
 * загрузок и с подходящим расширением.
 */
abstract class FileField extends Field
{
    /** Папка загрузки внутри корня или null — сам корень. */
    protected ?string $directory = null;

    /**
     * Допустимые расширения или null — все разрешённые в конфиге (для картинок — картинки).
     *
     * @var list<string>|null
     */
    protected ?array $extensions = null;

    /**
     * Папка внутри корня загрузок, куда кладутся файлы, загруженные в этом поле.
     * Выбрать в окне можно файл из любой папки.
     *
     * ```php
     * Image::make('photo')->directory('doctors'); // uploads/doctors
     * ```
     *
     * Имя каждой папки — строчная латиница, цифры и дефис (как в менеджере файлов), вложенность через `/`:
     * `doctor_photos` и `Doctors` не подходят, их заменяют `doctor-photos` и `doctors`.
     *
     * @throws LogicException имя папки не подходит
     */
    public function directory(string $directory): static
    {
        $directory = trim($directory, '/');

        foreach ($directory === '' ? [] : explode('/', $directory) as $segment) {
            if ($segment === '' || FileName::folder($segment) !== $segment) {
                throw new LogicException(__('fastleo::exceptions.directory_invalid', [
                    'field' => $this->name,
                    'directory' => $directory,
                    'example' => implode('/', array_filter(array_map(FileName::folder(...), explode('/', $directory))) ?: ['doctors']),
                ], Fastleo::LOCALE));
            }
        }

        $this->directory = $directory;

        return $this;
    }

    /**
     * Какие расширения принимает поле (из разрешённых в `fastleo.files.extensions`).
     *
     * ```php
     * File::make('price')->extensions(['pdf', 'xlsx']);
     * ```
     *
     * @param  list<string>  $extensions
     */
    public function extensions(array $extensions): static
    {
        $this->extensions = array_map(static fn (string $extension): string => strtolower(trim($extension, '. ')), $extensions);

        return $this;
    }

    /**
     * Папка загрузки: корень или корень + `directory()`.
     */
    public function uploadFolder(): string
    {
        $folder = PathGuard::root().($this->directory === null || $this->directory === '' ? '' : '/'.$this->directory);

        return PathGuard::inside($folder) ?? PathGuard::root();
    }

    /**
     * Расширения, которые подходят полю.
     *
     * @return list<string>
     */
    public function acceptedExtensions(): array
    {
        $available = $this->onlyImages() ? FileTypes::images() : FileTypes::allowed();

        return $this->extensions === null ? $available : array_values(array_intersect($available, $this->extensions));
    }

    /**
     * Поле принимает только картинки.
     */
    public function onlyImages(): bool
    {
        return false;
    }

    /**
     * Адрес окна выбора для этого поля.
     *
     * @param  string|null  $current  текущий файл — окно откроется в его папке
     */
    public function pickerUrl(?string $current = null, bool $multiple = false): string
    {
        return route('fastleo.files.index', array_filter([
            'picker' => 'field',
            'accept' => $this->onlyImages() ? 'images' : null,
            'multiple' => $multiple ? '1' : null,
            'current' => $current,
            'folder' => $current === null ? $this->uploadFolder() : null,
        ]));
    }

    /**
     * Правило пути для одного значения.
     */
    protected function pathRule(): FilePathRule
    {
        return new FilePathRule($this->acceptedExtensions());
    }

    /**
     * Ссылка на файл или миниатюра картинки для списка и просмотра.
     */
    protected function preview(string $path, bool $large = false): Htmlable
    {
        $url = e(FileStore::url($path));

        if (FileTypes::isImage($path)) {
            $src = e(route('fastleo.files.thumb', ['path' => $path]));
            $size = $large ? 'max-h-48 max-w-full' : 'size-10';

            return new HtmlString('<a href="'.$url.'" target="_blank" rel="noopener" class="inline-block"><img src="'.$src.'" alt="" loading="lazy" class="'.$size.' rounded-md object-cover"></a>');
        }

        return new HtmlString('<a href="'.$url.'" target="_blank" rel="noopener" class="text-primary-text hover:underline">'.e(basename($path)).'</a>');
    }

    /**
     * Строка-путь или null.
     */
    protected static function path(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Пустое значение здесь имеет свой смысл (галочка, пароль, файл, список, вложенные записи) — `emptyValue()` недоступен.
     */
    #[Override]
    protected function supportsEmptyValue(): bool
    {
        return false;
    }
}
