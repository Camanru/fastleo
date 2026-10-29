<?php

declare(strict_types=1);

namespace Fastleo\Fields\Concerns;

use Fastleo\Files\GenerateImageVariants;
use Fastleo\Files\PathGuard;
use Fastleo\Files\Variants;

/**
 * Варианты картинок для сайта у полей Image и Gallery: после сохранения записи для новых
 * картинок создаются WebP-варианты размеров из `fastleo.images.presets` (в очереди).
 */
trait HasImageVariants
{
    /**
     * Имена размеров из `fastleo.images.presets`.
     *
     * @var list<string>
     */
    protected array $variants = [];

    /**
     * Создавать для картинок поля варианты этих размеров. Вывод на сайте —
     * `<x-fastleo::picture :src="$doctor->photo" preset="card" />`.
     *
     * ```php
     * Image::make('photo')->variants('card', 'hero');
     * ```
     */
    public function variants(string ...$presets): static
    {
        foreach ($presets as $preset) {
            Variants::preset($preset);
        }

        $this->variants = array_values(array_unique($presets));

        return $this;
    }

    /**
     * Размеры вариантов поля.
     *
     * @return list<string>
     */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /**
     * Поставить в очередь создание вариантов для картинок (после фиксации транзакции).
     *
     * @param  list<string>  $paths
     */
    public function queueVariants(array $paths): void
    {
        if ($this->variants === []) {
            return;
        }

        foreach ($paths as $path) {
            if (PathGuard::entry($path) !== null) {
                dispatch(new GenerateImageVariants($path, $this->variants))->afterCommit();
            }
        }
    }
}
