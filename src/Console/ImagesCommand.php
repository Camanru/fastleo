<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Fields\Field;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\Image;
use Fastleo\Fields\Nested;
use Fastleo\Files\Variants;
use Fastleo\Resource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Пересоздаёт WebP-варианты картинок всех полей Image и Gallery с `variants()`
 * (в том числе внутри вложенных блоков). Нужна, когда поменяли размеры
 * `fastleo.images.presets` или водяной знак.
 *
 * ```bash
 * php artisan fastleo:images
 * php artisan fastleo:images --preset=card
 * ```
 */
#[AsCommand(name: 'fastleo:images')]
#[Description('Пересоздать варианты картинок для сайта (WebP, водяной знак)')]
#[Signature('fastleo:images {--preset=* : Только эти размеры из fastleo.images.presets}')]
final class ImagesCommand extends Command
{
    /**
     * Собрать картинки полей с вариантами и создать варианты заново.
     */
    public function handle(): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $only = array_values(array_filter((array) $this->option('preset'), is_string(...)));

        foreach ($only as $preset) {
            Variants::preset($preset);
        }

        /** @var array<string, list<string>> $images путь → размеры */
        $images = [];

        foreach (array_keys(Fastleo::resources()->all()) as $slug) {
            $resource = Fastleo::resources()->find($slug);

            if ($resource instanceof Resource) {
                $this->collect($resource->newModel(), $resource->schema()->fields(), $only, $images);
            }
        }

        $bar = $this->output->createProgressBar(count($images));

        foreach ($images as $path => $presets) {
            foreach ($presets as $preset) {
                Variants::generate($path, $preset);
            }

            $bar->advance();
        }

        $bar->clear();
        $this->components->info(__('fastleo::console.images_done', ['count' => count($images)]));

        return self::SUCCESS;
    }

    /**
     * Пути картинок полей модели (и её вложенных блоков) с нужными размерами.
     *
     * @param  list<Field>  $fields
     * @param  list<string>  $only
     * @param  array<string, list<string>>  $images
     */
    private function collect(Model $model, array $fields, array $only, array &$images): void
    {
        $columns = [];

        foreach ($fields as $field) {
            if ($field instanceof Nested) {
                $this->collect($field->relationInstance($model)->getRelated(), $field->schema()->fields(), $only, $images);
            } elseif ($field instanceof Image || $field instanceof Gallery) {
                $presets = $only === [] ? $field->getVariants() : array_values(array_intersect($field->getVariants(), $only));

                if ($presets !== []) {
                    $columns[$field->name] = $presets;
                }
            }
        }

        if ($columns === []) {
            return;
        }

        $query = $model->newQuery();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        foreach ($query->select([$model->getKeyName(), ...array_keys($columns)])->lazyById() as $record) {
            foreach ($columns as $column => $presets) {
                $value = $record->getAttribute($column);

                foreach (is_array($value) ? $value : [$value] as $path) {
                    if (is_string($path) && $path !== '') {
                        $images[$path] = array_values(array_unique([...$images[$path] ?? [], ...$presets]));
                    }
                }
            }
        }
    }
}
