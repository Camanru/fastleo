<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Closure;
use Fastleo\Files\FileTypes;
use Fastleo\Files\PathGuard;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Значение поля файла — путь внутри корня загрузок с подходящим расширением.
 *
 * Без этой проверки поле приняло бы любой путь на диске (`../.env`, чужая папка):
 * значение пришло из формы и могло быть подменено. Есть ли файл на диске, не проверяется:
 * путь даёт только окно выбора или загрузка, а файл, удалённый в менеджере, не должен
 * мешать сохранить запись.
 *
 * ```php
 * new FilePathRule(['jpg', 'png']);
 * ```
 */
final readonly class FilePathRule implements ValidationRule
{
    /**
     * @param  list<string>  $extensions  допустимые расширения в нижнем регистре
     */
    public function __construct(private array $extensions) {}

    /**
     * Проверить значение.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $path = PathGuard::entry($value);

        if ($path === null) {
            $fail(__('fastleo::files.invalid_path'));

            return;
        }

        if (! in_array(FileTypes::extension($path), $this->extensions, true)) {
            $fail(__('fastleo::files.invalid_extension', ['extensions' => implode(', ', $this->extensions)]));
        }
    }
}
