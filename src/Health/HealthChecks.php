<?php

declare(strict_types=1);

namespace Fastleo\Health;

use Closure;
use Fastleo\Fastleo;
use Fastleo\Support\ServerReport;
use LogicException;
use Throwable;

/**
 * Проверки сайта для отчёта в SoftNetica: встроенные (из отчёта «Состояние сервера») и свои, добавленные через
 * `Fastleo::healthCheck()`. Упавшая проверка не ломает отчёт, а превращается в `failed`.
 *
 * @phpstan-type Check array{name: string, label: string, status: string, summary?: string, message?: string}
 */
final class HealthChecks
{
    /** Сколько проверок принимает панель за один отчёт. */
    public const int MAX_CHECKS = 30;

    /** Имена встроенных проверок: свои проверки не могут их занимать. */
    public const array RESERVED = [
        'debug', 'maintenance', 'cache', 'queue', 'scheduler', 'mail', 'storage_link', 'writable', 'disk',
        'post_max_size', 'opcache', 'database_ping', 'failed_jobs',
    ];

    /** Допустимое имя проверки. */
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_.:-]*$/i';

    /** @var array<string, array{name: string, label: string, check: Closure(): mixed}> */
    private array $custom = [];

    /**
     * Добавить свою проверку. Вызывается в `AppServiceProvider::boot()`; замыкание выполняется при каждом отчёте
     * (раз в 5 минут) и должно быть быстрым.
     *
     * @param  Closure(): mixed  $check  должна вернуть `HealthResult`; другой результат считается сбоем проверки
     *
     * @throws LogicException имя недопустимо, занято или проверок слишком много
     */
    public function register(string $name, string $label, Closure $check): void
    {
        $key = strtolower($name);

        if (preg_match(self::NAME_PATTERN, $name) !== 1 || strlen($name) > 64) {
            throw new LogicException(__('fastleo::softnetica.check_name_invalid', ['name' => $name], Fastleo::LOCALE));
        }

        if (in_array($key, self::RESERVED, true) || isset($this->custom[$key])) {
            throw new LogicException(__('fastleo::softnetica.check_name_taken', ['name' => $name], Fastleo::LOCALE));
        }

        if (trim($label) === '') {
            throw new LogicException(__('fastleo::softnetica.check_label_empty', ['name' => $name], Fastleo::LOCALE));
        }

        if (count($this->custom) >= self::MAX_CHECKS - count(self::RESERVED)) {
            throw new LogicException(__('fastleo::softnetica.check_too_many', ['max' => self::MAX_CHECKS - count(self::RESERVED)], Fastleo::LOCALE));
        }

        $this->custom[$key] = ['name' => $name, 'label' => $label, 'check' => $check];
    }

    /**
     * Все проверки для отчёта: встроенные и свои, в формате панели.
     *
     * @return list<Check>
     */
    public function collect(): array
    {
        $checks = [];

        foreach (ServerReport::sections() as $section) {
            foreach ($section['rows'] as $row) {
                if ($row['status'] === null) {
                    continue;
                }

                $checks[] = $this->format($row['key'], $row['title'], $row['status'] === 'danger' ? 'failed' : $row['status'], $row['value'], $row['hint']);
            }
        }

        return array_slice([...$checks, ...$this->custom()], 0, self::MAX_CHECKS);
    }

    /**
     * Только свои проверки сайта: выполняются сразу, сбой замыкания не роняет остальные.
     *
     * @return list<Check>
     */
    public function custom(): array
    {
        return array_values(array_map(fn (array $custom): array => $this->run($custom['name'], $custom['label'], $custom['check']), $this->custom));
    }

    /**
     * Выполнить свою проверку; сбой или неверный результат — `failed`.
     *
     * @param  Closure(): mixed  $check
     * @return Check
     */
    private function run(string $name, string $label, Closure $check): array
    {
        try {
            $result = $check();
        } catch (Throwable $exception) {
            report($exception);

            return $this->format($name, $label, 'failed', __('fastleo::softnetica.check_crashed', [], Fastleo::LOCALE), null);
        }

        if (! $result instanceof HealthResult) {
            return $this->format($name, $label, 'failed', __('fastleo::softnetica.check_invalid', [], Fastleo::LOCALE), null);
        }

        $text = $result->message;

        return $this->format($name, $label, $result->status, $text, $text !== null && mb_strlen($text) > 200 ? $text : null);
    }

    /**
     * Одна проверка в формате панели: тексты очищены и обрезаны, пустые поля не отправляются.
     *
     * @return Check
     */
    private function format(string $name, string $label, string $status, ?string $summary, ?string $message): array
    {
        $check = ['name' => $name, 'label' => HealthText::clean($label, 100), 'status' => $status];

        $summaryText = $summary === null ? '' : HealthText::clean($summary, 200);
        $messageText = $message === null ? '' : HealthText::clean($message, 500);

        if ($summaryText !== '') {
            $check['summary'] = $summaryText;
        }

        if ($messageText !== '') {
            $check['message'] = $messageText;
        }

        return $check;
    }
}
