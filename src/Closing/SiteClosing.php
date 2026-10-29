<?php

declare(strict_types=1);

namespace Fastleo\Closing;

use Fastleo\Fastleo;
use Fastleo\Http\Middleware\CloseSiteForVisitors;
use Fastleo\Support\SettingsStore;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Throwable;

/**
 * «Закрытие сайта»: пока в настройках стоит галочка, посетители видят страницу «Сайт временно не работает»
 * (код 503), а вошедшие администраторы и сама админка работают как обычно. Включается ключом
 * `fastleo.closing.enabled` — до этого страницы настроек и проверки на запросах нет.
 */
final class SiteClosing
{
    /** Группы маршрутов сайта, на которые вешается проверка. */
    private const array GROUPS = ['web', 'api'];

    /**
     * Функция включена в конфиге.
     */
    public static function enabled(): bool
    {
        return config()->boolean('fastleo.closing.enabled', false);
    }

    /**
     * Подключить проверку к группам `web` и `api` (у `api` ответ всегда JSON). Вызывается провайдером при старте,
     * если функция включена. Через Kernel, а не через роутер: другие пакеты (Sanctum, debugbar) позже
     * пересобирают группы роутера из списков Kernel, и добавленное только в роутер пропало бы.
     */
    public static function register(): void
    {
        /** @var HttpKernelContract $kernel */
        $kernel = resolve(HttpKernelContract::class);

        if (! $kernel instanceof HttpKernel) {
            return;
        }

        foreach (self::GROUPS as $group) {
            if (isset($kernel->getMiddlewareGroups()[$group])) {
                $kernel->appendMiddlewareToGroup($group, $group === 'api' ? CloseSiteForVisitors::class.':json' : CloseSiteForVisitors::class);
            }
        }
    }

    /**
     * Сайт закрыт для посетителей сейчас. Если настройки прочитать нельзя (таблица ещё не создана, сбой кеша),
     * сайт остаётся открытым: ошибка чтения не должна ронять весь публичный сайт. Настройки читаются прямо
     * из хранилища, без реестра разделов: он обходит папку ресурсов и бросает исключения при ошибках в описании.
     */
    public static function closed(): bool
    {
        return self::enabled() && filter_var(self::stored()['closed'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Текст для посетителей: из настроек, а если пусто — стандартный.
     */
    public static function message(): string
    {
        $message = self::stored()['message'] ?? null;

        return is_string($message) && trim($message) !== '' ? $message : __('fastleo::closing.default_message', locale: Fastleo::LOCALE);
    }

    /**
     * Сохранённые значения страницы «Закрытие сайта»; при сбое чтения — пусто (сайт открыт), сбой попадает в журнал.
     *
     * @return array<string, mixed>
     */
    private static function stored(): array
    {
        try {
            return resolve(SettingsStore::class)->values('closing');
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
