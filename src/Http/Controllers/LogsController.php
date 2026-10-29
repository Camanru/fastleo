<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Logs\LogReader;
use Fastleo\Support\CacheCleaner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Просмотр журнала ошибок Laravel в админке: записи от новых к старым по 50, фильтр уровня, поиск, очистка.
 * Только файлы `*.log` из папки журнала приложения.
 */
final class LogsController
{
    /** Записей на странице. */
    private const int PER_PAGE = 50;

    /**
     * Записи выбранного журнала.
     */
    public function index(Request $request): View
    {
        abort_unless(LogReader::canView(), 403);

        $reader = LogReader::forApp();
        // Не строки (?q[]=…) считаются пустыми значениями.
        $query = fn (string $key): string => is_string($request->query($key)) ? $request->query($key) : '';
        $level = array_key_exists($query('level'), LogReader::FILTERS) ? $query('level') : 'errors';
        $search = mb_substr(trim($query('q')), 0, 100);
        $before = $query('before') !== '' && ctype_digit($query('before')) ? (int) $query('before') : null;
        $path = $reader->path($query('file') !== '' ? $query('file') : null);

        return view('fastleo::logs.index', [
            'files' => $reader->files(),
            'file' => $path === null ? null : basename($path),
            'level' => $level,
            'search' => $search,
            'before' => $before,
            'size' => $path === null ? 0 : (int) filesize($path),
            'result' => $path === null ? null : $reader->read($path, $before, self::PER_PAGE, LogReader::FILTERS[$level], $search),
        ]);
    }

    /**
     * Очистить журнал (файл обнуляется, не удаляется).
     */
    public function clear(Request $request): RedirectResponse
    {
        abort_unless(LogReader::canView(), 403);

        $name = is_string($request->input('file')) ? $request->input('file') : '';
        $size = LogReader::forApp()->clear($name);
        abort_if($size === null, 404);

        return to_route('fastleo.logs.index', ['file' => $name])->with('fastleo.success', __('fastleo::logs.cleared', ['name' => $name, 'size' => CacheCleaner::format($size)]));
    }
}
