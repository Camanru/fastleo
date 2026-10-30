<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Settings;
use Fastleo\SoftNetica\SyncState;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Хранилище значений страниц настроек: одна строка таблицы `fastleo_settings` на страницу, значения — JSON-объект.
 * Все страницы читаются одним запросом и держатся в кеше приложения до следующего сохранения.
 * Служебные строки пакета (состояние связи с SoftNetica) в это хранилище не попадают.
 * Значения полей из `Settings::encrypted()` лежат в базе зашифрованными (ключ приложения).
 */
final class SettingsStore
{
    /** Ключ кеша со всеми строками. */
    private const string CACHE_KEY = 'fastleo.settings';

    /**
     * Значения страницы: поле → значение (зашифрованные поля уже расшифрованы). Страницы ещё нет — пустой массив.
     *
     * @return array<string, mixed>
     */
    public function values(string $slug, ?Settings $page = null): array
    {
        $json = $this->rows()[$slug] ?? null;
        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        $values = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $values[$key] = $page instanceof Settings && in_array($key, $page->encrypted(), true) ? $this->decrypt($value) : $value;
        }

        return $values;
    }

    /**
     * Сохранить значения страницы (прежние заменяются целиком) и сбросить кеш.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(Settings $page, array $values): void
    {
        foreach ($page->encrypted() as $key) {
            if (isset($values[$key]) && $values[$key] !== '') {
                $values[$key] = Crypt::encryptString(json_encode($values[$key], JSON_THROW_ON_ERROR));
            }
        }

        DB::table('fastleo_settings')->upsert(
            [['slug' => $page::slug(), 'value' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]],
            ['slug'],
            ['value', 'updated_at'],
        );

        $this->flush();
    }

    /**
     * Забыть кеш (после сохранения; пригодится, если таблицу правили руками).
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Забыть кеш при `optimize` и `optimize:clear` (после деплоя, сидера или правки таблицы руками). Если хранилище
     * кеша недоступно (сборка образа без базы), молча пропускает: сброс не должен ронять `optimize`.
     */
    public static function forgetCached(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // кеша нет — забывать нечего
        }
    }

    /**
     * Строки таблицы: адрес страницы → JSON.
     *
     * @return array<string, mixed>
     */
    private function rows(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $rows = [];

            foreach (DB::table('fastleo_settings')->where('slug', '!=', SyncState::SLUG)->get(['slug', 'value']) as $row) {
                if (is_string($row->slug)) {
                    $rows[$row->slug] = $row->value;
                }
            }

            return $rows;
        });
    }

    /**
     * Расшифровать значение; не получилось (ключ приложения сменили) — null.
     */
    private function decrypt(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            return json_decode(Crypt::decryptString($value), true);
        } catch (DecryptException) {
            return null;
        }
    }
}
