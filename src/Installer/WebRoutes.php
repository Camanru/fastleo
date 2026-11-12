<?php

declare(strict_types=1);

namespace Fastleo\Installer;

use Illuminate\Support\Facades\File;
use PhpToken;

/**
 * Правки `routes/web.php` сайта для публичной части готовых модулей: маршруты каждого модуля дописываются в конец файла
 * кодом без комментариев, а нетронутый пример главной страницы Laravel убирается. Всё делается одной записью файла.
 * Блок, который уже есть в файле (тот же код), второй раз не пишется. Блок запасного маршрута (`Route::fallback` страниц)
 * и маршрут лендинга (`/{landing?}`) остаются в конце файла: маршруты модулей, поставленных позже, вставляются выше них. Запасной
 * маршрут Laravel сопоставляет последним независимо от места в файле, а маршрут лендинга подходит под любой адрес из одного
 * сегмента, поэтому ниже `/news` и подобных он стоять обязан.
 *
 * ```php
 * $web = new WebRoutes();
 * $web->append(['news' => "\\Illuminate\\Support\\Facades\\Route::get('/news', …);"], replaceWelcome: true);
 * // ['appended' => ['news'], 'welcomeReplaced' => true]; повторный вызов ничего не меняет
 * ```
 */
final class WebRoutes
{
    /**
     * Пример главной страницы из нового Laravel. Привязан к началу строки: закомментированный пример и пример
     * внутри группы маршрутов (с отступом) не считаются нетронутыми, чужой код не затрагивается.
     */
    private const string WELCOME = '~^Route::get\(\s*[\'"]/[\'"]\s*,\s*function\s*\(\)\s*(?::\s*\w+\s*)?\{\s*return view\(\s*[\'"]welcome[\'"]\s*\)\s*;\s*\}\s*\)\s*;[ \t]*\R?~m';

    /**
     * Дописать в конец файла маршруты модулей (имя модуля → код без `<?php`), если такого кода там ещё нет, и при
     * `$replaceWelcome` убрать нетронутый пример главной страницы Laravel (маршрут и стандартный шаблон `welcome`:
     * переписанный под сайт шаблон считается главной сайта). Код из `$catchAll` (запасной маршрут «Страниц» и маршрут лендинга) пишется после остальных,
     * а если он уже есть в файле, новые блоки вставляются перед ним. Возвращает, что изменено сейчас; ничего менять не
     * нужно — файл не пишется.
     *
     * @param  array<string, string>  $blocks
     * @param  list<string>  $catchAll  код блоков, которые должны стоять в конце файла
     * @return array{appended: list<string>, welcomeReplaced: bool}
     */
    public function append(array $blocks, bool $replaceWelcome, array $catchAll = []): array
    {
        $file = $this->read();
        $crlf = str_contains($file, "\r\n");
        $routes = str_replace("\r\n", "\n", $file);
        $catchAll = array_map($this->normalize(...), $catchAll);
        $new = array_filter(array_map($this->normalize(...), $blocks), fn (string $code): bool => $this->find($routes, $code) === null);
        $welcomeReplaced = $replaceWelcome && preg_match(self::WELCOME, $routes) === 1 && $this->welcomeViewIsStock();

        if ($new === [] && ! $welcomeReplaced) {
            return ['appended' => [], 'welcomeReplaced' => false];
        }

        if ($welcomeReplaced) {
            $routes = (string) preg_replace(self::WELCOME, '', $routes, 1);
        }

        $ordinary = array_filter($new, fn (string $code): bool => ! in_array($code, $catchAll, true));
        $last = array_diff_key($new, $ordinary);
        // В конце файла блоки стоят в том порядке, в каком их перечислили в `$catchAll`: запасной маршрут после маршрута лендинга (если оба новые).
        uasort($last, fn (string $a, string $b): int => array_search($a, $catchAll, true) <=> array_search($b, $catchAll, true));
        $appended = array_keys([...$ordinary, ...$last]);

        if ($new !== []) {
            // Закрывающий тег PHP снят, иначе дописанный код вышел бы текстом страницы.
            $routes = trim($routes) === '' ? "<?php\n" : rtrim((string) preg_replace('/\?>\s*$/', '', $routes))."\n";
            $anchor = $this->lineStart($routes, $catchAll);

            // Запасной маршрут или маршрут лендинга уже в файле: остальные блоки вставляются выше самого раннего из них.
            if ($anchor !== null && $ordinary !== []) {
                $routes = rtrim(substr($routes, 0, $anchor))."\n\n".implode("\n\n", $ordinary)."\n\n".substr($routes, $anchor);
                $ordinary = [];
            }

            foreach ([...$ordinary, ...$last] as $code) {
                $routes .= "\n".$code."\n";
            }
        }

        // Переводы строк Windows (CRLF) сохраняются.
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), $crlf ? str_replace("\n", "\r\n", $routes) : $routes);

        return ['appended' => $appended, 'welcomeReplaced' => $welcomeReplaced];
    }

    /**
     * Этот код маршрутов уже есть в файле.
     */
    public function has(string $code): bool
    {
        return $this->find(str_replace("\r\n", "\n", $this->read()), $this->normalize($code)) !== null;
    }

    /**
     * Позиция блока `$code` в тексте файла; null — блока нет. Полные имена классов (`\Illuminate\Support\Facades\Route`) в
     * файле могут стоять сокращёнными до `Route` с `use` наверху: так их переписывает Pint с пресетом Laravel, — и блок,
     * записанный установщиком, остаётся тем же блоком.
     */
    private function find(string $routes, string $code): ?int
    {
        $parts = preg_split('~(\\\\(?:\w+\\\\)+\w+)~', $code, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$code];
        $pattern = '';

        foreach ($parts as $index => $part) {
            // Нечётные части — полные имена: пространство имён перед последним сегментом необязательно.
            $pattern .= $index % 2 === 1
                ? '(?:\\\\?(?:\w+\\\\)+)?'.preg_quote(substr($part, (int) strrpos($part, '\\') + 1), '~')
                // Пробелы и переводы строк между частями кода гибкие: сайт мог вставить пустую строку или отступ группы.
                : (string) preg_replace('/\s+/', '\\\\s+', preg_quote($part, '~'));
        }

        // Закомментированный блок — не блок: комментарии заменяются пробелами, смещения остаются прежними.
        return preg_match('~(?<!\w)'.$pattern.'~', self::withoutComments($routes), $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : null;
    }

    /**
     * Текст файла, в котором все виды комментариев (`//`, `#` и блочные) заменены пробелами той же длины.
     */
    public static function withoutComments(string $routes): string
    {
        return implode('', array_map(
            fn (PhpToken $token): string => $token->is([T_COMMENT, T_DOC_COMMENT]) ? (string) preg_replace('/[^\n]/', ' ', $token->text) : $token->text,
            PhpToken::tokenize($routes),
        ));
    }

    /**
     * Позиция, с которой начинается верхнеуровневая запись файла, содержащая `$position`: блок маршрутов, поставленный
     * сайтом внутрь группы (`Route::middleware('auth')->group(function () { … })`), считается всей группой, и новые
     * маршруты встают выше неё, а не внутрь.
     */
    private function statementStart(string $routes, int $position): int
    {
        $depth = 0;
        $start = 0;
        $offset = 0;

        foreach (PhpToken::tokenize($routes) as $token) {
            if ($offset >= $position) {
                break;
            }

            $depth += $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, '{']) ? 1 : ($token->is('}') ? -1 : 0);

            if ($depth === 0 && $token->is([';', T_OPEN_TAG])) {
                $start = $offset + strlen($token->text);
            }

            $offset += strlen($token->text);
        }

        return $start + strspn($routes, " \t\r\n", $start);
    }

    /**
     * Позиция начала строки, с которой в тексте начинается первый из блоков `$codes`; null — ни одного нет.
     *
     * @param  list<string>  $codes
     */
    private function lineStart(string $routes, array $codes): ?int
    {
        $positions = array_filter(array_map(fn (string $code): ?int => $this->find($routes, $code), $codes), fn (?int $position): bool => $position !== null);

        if ($positions === []) {
            return null;
        }

        $start = $this->statementStart($routes, min($positions));
        $newline = strrpos(substr($routes, 0, $start), "\n");

        // Файл в одну строку (`<?php Route::get(…); Route::fallback(…);`): вставка идёт перед записью, а не перед `<?php`.
        return $newline === false ? $start : $newline + 1;
    }

    /**
     * Код с переводами строк `\n` и без пустых краёв: так блоки сравниваются независимо от CRLF.
     */
    private function normalize(string $code): string
    {
        return trim(str_replace("\r\n", "\n", $code));
    }

    /**
     * Шаблон `welcome` не переписан под сайт: его нет (маршрут и так не открывается) или в нём стоит ссылка на документацию
     * Laravel, как в нетронутом шаблоне. Если шаблон правили, маршрут `/` — главная самого сайта, и его не убирают.
     */
    private function welcomeViewIsStock(): bool
    {
        $view = resource_path('views/welcome.blade.php');

        return ! File::exists($view) || str_contains(File::get($view), 'laravel.com/docs');
    }

    /**
     * Файл маршрутов сайта.
     */
    private function path(): string
    {
        return base_path('routes/web.php');
    }

    /**
     * Содержимое файла маршрутов, пустая строка — если файла нет.
     */
    private function read(): string
    {
        return File::exists($this->path()) ? File::get($this->path()) : '';
    }
}
