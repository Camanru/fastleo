<?php

declare(strict_types=1);

namespace Fastleo\Installer;

use Illuminate\Support\Facades\File;

/**
 * Правки `routes/web.php` сайта для публичной части готовых модулей: маршруты каждого модуля дописываются в конец файла
 * кодом без комментариев, а нетронутый пример главной страницы Laravel убирается. Всё делается одной записью файла.
 * Блок, который уже есть в файле (тот же код), второй раз не пишется. Блок запасного маршрута (`Route::fallback` страниц)
 * всегда остаётся последним: маршруты модулей, поставленных позже, вставляются выше него.
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
     * переписанный под сайт шаблон считается главной сайта). Код из `$catchAll` (запасной маршрут) пишется после остальных,
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
        $new = array_filter(array_map($this->normalize(...), $blocks), fn (string $code): bool => ! str_contains($routes, $code));
        $welcomeReplaced = $replaceWelcome && preg_match(self::WELCOME, $routes) === 1 && $this->welcomeViewIsStock();

        if ($new === [] && ! $welcomeReplaced) {
            return ['appended' => [], 'welcomeReplaced' => false];
        }

        if ($welcomeReplaced) {
            $routes = (string) preg_replace(self::WELCOME, '', $routes, 1);
        }

        $ordinary = array_filter($new, fn (string $code): bool => ! in_array($code, $catchAll, true));
        $last = array_diff_key($new, $ordinary);
        $appended = array_keys([...$ordinary, ...$last]);

        if ($new !== []) {
            // Закрывающий тег PHP снят, иначе дописанный код вышел бы текстом страницы.
            $routes = trim($routes) === '' ? "<?php\n" : rtrim((string) preg_replace('/\?>\s*$/', '', $routes))."\n";
            $anchor = $this->lineStart($routes, $catchAll);

            // Запасной маршрут уже в файле: остальные блоки вставляются выше него, он остаётся последним.
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
        return str_contains(str_replace("\r\n", "\n", $this->read()), $this->normalize($code));
    }

    /**
     * Позиция начала строки, с которой в тексте начинается первый из блоков `$codes`; null — ни одного нет.
     *
     * @param  list<string>  $codes
     */
    private function lineStart(string $routes, array $codes): ?int
    {
        $positions = array_filter(array_map(fn (string $code): int|false => strpos($routes, $code), $codes), is_int(...));

        if ($positions === []) {
            return null;
        }

        $newline = strrpos(substr($routes, 0, min($positions)), "\n");

        return $newline === false ? 0 : $newline + 1;
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
