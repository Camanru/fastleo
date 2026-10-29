<?php

declare(strict_types=1);

namespace Fastleo\Files;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

/**
 * Задача очереди: создать WebP-варианты картинки для размеров из конфига.
 * С очередью выполняется в фоне, без неё (`sync`) — сразу после сохранения записи.
 *
 * ```php
 * GenerateImageVariants::dispatch('uploads/doctors/ivanov.jpg', ['card', 'hero'])->afterCommit();
 * ```
 */
#[Tries(2)]
final class GenerateImageVariants implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $path  путь картинки на диске файлов
     * @param  list<string>  $presets  имена размеров из `fastleo.images.presets`
     */
    public function __construct(public string $path, public array $presets) {}

    /**
     * Создать варианты для каждого размера.
     */
    public function handle(): void
    {
        foreach ($this->presets as $preset) {
            Variants::generate($this->path, $preset);
        }
    }
}
