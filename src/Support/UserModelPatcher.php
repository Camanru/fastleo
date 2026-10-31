<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\HasFastleoAccess;
use Illuminate\Support\Facades\File;
use ParseError;
use PhpToken;
use ReflectionClass;
use Symfony\Component\Process\Process;

/**
 * Подключает модель пользователя сайта к админке: добавляет в её файл интерфейс
 * FastleoUser и трейт HasFastleoAccess (так делают установщики Breeze и Filament Shield).
 *
 * Файл меняется, только если модель устроена обычно (как `app/Models/User.php` нового
 * Laravel); иначе ничего не трогается — разработчик подключает модель сам.
 */
final class UserModelPatcher
{
    /**
     * Дописать интерфейс и трейт в файл модели.
     *
     * Возвращает путь изменённого файла или null, если файл не в папке `app` сайта
     * (модели пакетов и Laravel не трогаются), недоступен для записи или устроен необычно.
     *
     * ```php
     * UserModelPatcher::connect(\App\Models\User::class); // '/site/app/Models/User.php'
     * ```
     *
     * @param  class-string  $model
     */
    public static function connect(string $model): ?string
    {
        $file = (string) realpath((string) new ReflectionClass($model)->getFileName());
        $app = realpath(app_path());
        $code = $app !== false && str_starts_with($file, $app.DIRECTORY_SEPARATOR) && is_writable($file)
            ? self::patch(File::get($file), class_basename($model))
            : null;

        if ($code === null || ! self::compiles($code)) {
            return null;
        }

        File::put($file, $code);

        return $file;
    }

    /**
     * Код проходит `php -l`: ловит то, что не видит разбор токенов (повторный `use`, конфликт имён).
     * Так модель с ошибкой в самом файле не будет «подключена» молча. Без `proc_open` (закрыт на части хостингов)
     * проверить нельзя — ответ `false`, модель подключается вручную.
     *
     * ```php
     * UserModelPatcher::compiles('<?php class A {}'); // true
     * ```
     */
    public static function compiles(string $code): bool
    {
        return function_exists('proc_open') && new Process([PHP_BINARY, '-l'], input: $code)->run() === 0;
    }

    /**
     * Новый текст файла модели с интерфейсом и трейтом или null, если файл править нельзя:
     * в нём уже упоминается fastleo (модель подключали вручную), нет namespace,
     * объявление класса не найдено (или их несколько), переводы строк смешанные (LF и CRLF) или результат
     * не разбирается PHP. Переводы строк CRLF сохраняются.
     *
     * ```php
     * UserModelPatcher::patch($code, 'User'); // "…class User extends Authenticatable implements FastleoUser…"
     * ```
     */
    public static function patch(string $code, string $class): ?string
    {
        $crlf = str_contains($code, "\r\n");

        if ($crlf && preg_match('/(?<!\r)\n/', $code) === 1) {
            return null;
        }

        $code = str_replace("\r\n", "\n", $code);

        if (str_contains($code, 'FastleoUser') || str_contains($code, 'HasFastleoAccess') || preg_match('/^namespace\s+[\w\\\\]+;$/m', $code) !== 1) {
            return null;
        }

        $declaration = '/^((?:(?:final|abstract|readonly)\s+)*class\s+'.preg_quote($class, '/').'\s+extends\s+[\w\\\\]+)(\s+implements\s+[\w\\\\,\s]+?)?(\s*)\{/m';

        if (preg_match_all($declaration, $code) !== 1) {
            return null;
        }

        preg_match($declaration, $code, $match, PREG_OFFSET_CAPTURE);
        $header = $match[1][0].($match[2][0] === '' ? ' implements FastleoUser' : $match[2][0].', FastleoUser').$match[3][0].'{';
        $bodyStart = $match[0][1] + strlen($match[0][0]);

        $code = self::addTrait($code, $bodyStart);
        $code = substr_replace($code, $header, $match[0][1], strlen($match[0][0]));
        $code = self::addImport($code, FastleoUser::class);
        $code = self::addImport($code, HasFastleoAccess::class);

        try {
            PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (ParseError) {
            return null;
        }

        return $crlf ? str_replace("\n", "\r\n", $code) : $code;
    }

    /**
     * Вставить `use Класс;` среди импортов файла по алфавиту (или сразу после namespace).
     */
    private static function addImport(string $code, string $import): string
    {
        $line = 'use '.$import.';';
        preg_match_all('/^use\s+[\w\\\\]+(?:\s+as\s+\w+)?;$/m', $code, $imports, PREG_OFFSET_CAPTURE);

        foreach ($imports[0] as [$existing, $offset]) {
            if (strcasecmp($existing, $line) > 0) {
                return substr_replace($code, $line."\n", $offset, 0);
            }
        }

        $last = end($imports[0]);

        if ($last !== false) {
            $end = $last[1] + strlen($last[0]);

            return substr_replace($code, "\n".$line, $end, 0);
        }

        return (string) preg_replace('/^(namespace\s+[\w\\\\]+;)$/m', "$1\n\n".$line, $code, 1);
    }

    /**
     * Добавить HasFastleoAccess в `use` трейтов, если он первый в теле класса (перед ним допустимы пробелы и комментарии),
     * по алфавиту; иначе — новой строкой сразу после открывающей скобки класса. `$bodyStart` — позиция после `{`.
     */
    private static function addTrait(string $code, int $bodyStart): string
    {
        if (preg_match('/\G(?:\s|\/\*.*?\*\/|\/\/[^\n]*)*(use\s+)([\w\\\\,\s]+);/s', $code, $match, PREG_OFFSET_CAPTURE, $bodyStart) === 1) {
            $traits = array_map(trim(...), explode(',', $match[2][0]));
            $position = collect($traits)->search(static fn (string $trait): bool => strcasecmp(class_basename($trait), 'HasFastleoAccess') > 0);
            array_splice($traits, $position === false ? count($traits) : $position, 0, ['HasFastleoAccess']);

            return substr_replace($code, implode(', ', $traits), $match[2][1], strlen($match[2][0]));
        }

        return substr_replace($code, "\n    use HasFastleoAccess;\n", $bodyStart, 0);
    }
}
