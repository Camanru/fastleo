<?php

declare(strict_types=1);

namespace Fastleo\Logs;

use Carbon\CarbonImmutable;
use Fastleo\Fastleo;
use Illuminate\Support\Facades\Gate;

/**
 * Чтение журналов Laravel (`storage/logs/*.log`) с конца, порциями: файл не загружается целиком, за один вызов
 * просматривается не больше 2 МБ. Продолжение — с позиции `next` (байт, с которого начинается самая старая
 * просмотренная запись), поэтому журнал в сотни мегабайт листается так же быстро, как маленький.
 *
 * Читаются только файлы `*.log` из папки журнала приложения; имя файла из запроса принимается лишь при точном
 * совпадении с одним из них (никаких `../`).
 *
 * @phpstan-type Entry array{offset: int, time: CarbonImmutable, env: string, level: string, message: string, details: string}
 */
final readonly class LogReader
{
    /** Размер одной порции чтения. */
    private const int CHUNK = 65536;

    /** Сколько байт просматривается за один вызов. */
    private const int MAX_SCAN = 2097152;

    /** Самое длинное сообщение записи. */
    private const int MESSAGE_LIMIT = 1000;

    /** Самые длинные подробности записи (трассировка). */
    private const int DETAILS_LIMIT = 20480;

    /** Начало записи: «[2026-10-09 15:13:02] production.ERROR: …». */
    private const string HEADER = '/^\[(\d{4}-\d\d\-\d\d[ T]\d\d:\d\d:\d\d)[^\]]*\] ([\w-]+)\.([A-Z]+): /m';

    /**
     * Фильтры уровней: «Ошибки», «Предупреждения и ошибки», «Все» (null).
     *
     * @var array<string, list<string>|null>
     */
    public const array FILTERS = [
        'errors' => ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'],
        'warnings' => ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'],
        'all' => null,
    ];

    /**
     * @param  string  $directory  папка с журналами
     */
    public function __construct(private string $directory) {}

    /**
     * Читатель журналов приложения (папка канала `single`, по умолчанию `storage/logs`).
     */
    public static function forApp(): self
    {
        return new self(dirname(config()->string('logging.channels.single.path', storage_path('logs/laravel.log'))));
    }

    /**
     * Журналы разрешено открывать: они включены (`fastleo.logs.enabled`) и Gate `viewFastleoLogs`
     * (если сайт его описал) разрешает.
     */
    public static function canView(): bool
    {
        if (! config()->boolean('fastleo.logs.enabled', true)) {
            return false;
        }

        return ! Gate::has('viewFastleoLogs') || Gate::forUser(Fastleo::guard()->user())->allows('viewFastleoLogs');
    }

    /**
     * Журналы папки, новые первыми.
     *
     * @return list<array{name: string, size: int, modified: int}>
     */
    public function files(): array
    {
        $files = [];

        foreach (glob($this->directory.'/*.log') ?: [] as $path) {
            $files[] = ['name' => basename($path), 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)];
        }

        usort($files, fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * Путь к журналу по имени из `files()`; другое имя или null — самый свежий журнал.
     */
    public function path(?string $name): ?string
    {
        $files = array_column($this->files(), 'name');
        $name = in_array($name, $files, true) ? $name : ($files[0] ?? null);

        return $name === null ? null : $this->directory.'/'.$name;
    }

    /**
     * Очистить журнал: файл обнуляется, а не удаляется — приложение продолжает писать в него с теми же правами.
     * Только файл из `files()` с точно таким именем; возвращает прежний размер или null, если такого файла нет.
     */
    public function clear(string $name): ?int
    {
        if (! in_array($name, array_column($this->files(), 'name'), true)) {
            return null;
        }

        $path = $this->directory.'/'.$name;
        $size = (int) filesize($path);
        $handle = fopen($path, 'r+');

        if ($handle === false) {
            return null;
        }

        flock($handle, LOCK_EX);
        ftruncate($handle, 0);
        flock($handle, LOCK_UN);
        fclose($handle);
        clearstatcache(true, $path);

        return $size;
    }

    /**
     * Записи от новых к старым, начиная с байта `$before` (null — с конца файла).
     *
     * @param  list<string>|null  $levels  null — все уровни
     * @return array{entries: list<Entry>, next: int|null, scanned: int}
     */
    public function read(string $path, ?int $before, int $limit, ?array $levels = null, string $search = ''): array
    {
        $handle = is_file($path) ? fopen($path, 'rb') : false;

        if ($handle === false) {
            return ['entries' => [], 'next' => null, 'scanned' => 0];
        }

        $size = (int) filesize($path);
        $position = max(0, min($before ?? $size, $size));
        $start = $position;
        $buffer = '';
        $entries = [];
        $scanned = 0;
        $next = null;

        while ($position > 0 && count($entries) < $limit && $scanned < self::MAX_SCAN) {
            $length = min(self::CHUNK, $position);
            $position -= $length;
            fseek($handle, $position);
            $buffer = fread($handle, $length).$buffer;
            $scanned += $length;

            preg_match_all(self::HEADER, $buffer, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            // Совпадение в самом начале буфера может оказаться серединой строки — его разберём со следующей порцией.
            $matches = array_values(array_filter($matches, fn (array $match): bool => $match[0][1] > 0 || $position === 0));

            if ($matches === []) {
                continue;
            }

            $end = strlen($buffer);

            foreach (array_reverse($matches) as $match) {
                $entryStart = $match[0][1];
                $text = substr($buffer, $entryStart, $end - $entryStart);
                $end = $entryStart;

                if (($levels === null || in_array($match[3][0], $levels, true)) && ($search === '' || mb_stripos($text, $search) !== false)) {
                    $entries[] = $this->entry($position + $entryStart, $match, $text);

                    if (count($entries) >= $limit) {
                        $next = $position + $entryStart;

                        break 2;
                    }
                }
            }

            // Разобранные записи убираем из буфера, остаётся начало файла до первой из них.
            $buffer = substr($buffer, 0, $matches[0][0][1]);
        }

        fclose($handle);

        if ($next === null && $position > 0) {
            // Лимит просмотра: продолжить с неразобранного остатка, а если запись длиннее лимита — пропустить её хвост.
            $next = $position + strlen($buffer) < $start ? $position + strlen($buffer) : $position;
        }

        return ['entries' => $entries, 'next' => $next ?: null, 'scanned' => $scanned];
    }

    /**
     * Запись журнала из совпадения заголовка и текста.
     *
     * @param  array<int, array{0: string, 1: int}>  $match
     * @return Entry
     */
    private function entry(int $offset, array $match, string $text): array
    {
        $body = rtrim(substr($text, strlen($match[0][0])));
        $newline = strpos($body, "\n");
        $firstLine = $newline === false ? $body : substr($body, 0, $newline);
        $details = $newline === false ? '' : ltrim(substr($body, $newline + 1));

        // Контекст исключения Laravel пишет в первой строке: {"exception":"[object] (…)"}.
        if (preg_match('/^(.*?) (\{"exception":.*)$/s', $firstLine, $parts) === 1) {
            $firstLine = $parts[1];
            $details = trim($parts[2]."\n".$details);
        }

        return [
            'offset' => $offset,
            'time' => CarbonImmutable::parse($match[1][0], config()->string('app.timezone', 'UTC')),
            'env' => $match[2][0],
            'level' => $match[3][0],
            'message' => mb_strimwidth($firstLine, 0, self::MESSAGE_LIMIT, '…'),
            'details' => strlen($details) > self::DETAILS_LIMIT
                ? mb_strcut($details, 0, self::DETAILS_LIMIT)."\n… (обрезано)"
                : $details,
        ];
    }
}
