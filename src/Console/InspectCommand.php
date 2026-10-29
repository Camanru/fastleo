<?php

declare(strict_types=1);

namespace Fastleo\Console;

use Fastleo\Fastleo;
use Fastleo\Page;
use Fastleo\Settings;
use Fastleo\Support\ResourceInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Показывает, как сайт настроил админку: разделы, поля, правила проверки, связи, фильтры, действия, права.
 * С `--json` отдаёт те же данные машине — Claude читает по ним текущее состояние сайта.
 *
 * ```bash
 * php artisan fastleo:inspect            # все разделы, настройки и страницы
 * php artisan fastleo:inspect articles   # один раздел по адресу
 * php artisan fastleo:inspect --json
 * ```
 */
#[AsCommand(name: 'fastleo:inspect')]
#[Description('Показать разделы, поля и права админки (--json — для Claude)')]
#[Signature('fastleo:inspect {resource? : адрес раздела, например articles} {--json : вывести JSON}')]
final class InspectCommand extends Command
{
    /**
     * Собрать описание и вывести его таблицами или JSON.
     */
    public function handle(ResourceInspector $inspector): int
    {
        app()->setLocale(Fastleo::LOCALE);

        $registry = Fastleo::resources();
        $slug = $this->argument('resource');

        if (is_string($slug) && $slug !== '') {
            $resource = $registry->find($slug);

            if (! $resource instanceof \Fastleo\Resource) {
                $this->components->error(__('fastleo::console.inspect_not_found', ['slug' => $slug, 'list' => implode(', ', array_keys($registry->all()))]));

                return self::FAILURE;
            }

            $info = $inspector->resource($resource);

            return $this->output($info, [$info], [], []);
        }

        $resources = [];

        foreach (array_keys($registry->all()) as $key) {
            $resource = $registry->find($key);

            if ($resource instanceof \Fastleo\Resource) {
                $resources[] = $inspector->resource($resource);
            }
        }

        $settings = [];

        foreach (array_keys($registry->settings()) as $key) {
            $page = $registry->findSettings($key);

            if ($page instanceof Settings) {
                $settings[] = $inspector->settings($page);
            }
        }

        $pages = [];

        foreach (array_keys($registry->pages()) as $key) {
            $page = $registry->findPage($key);

            if ($page instanceof Page) {
                $pages[] = $inspector->page($page);
            }
        }

        return $this->output(['resources' => $resources, 'settings' => $settings, 'pages' => $pages], $resources, $settings, $pages);
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<array<string, mixed>>  $resources
     * @param  list<array<string, mixed>>  $settings
     * @param  list<array<string, mixed>>  $pages
     */
    private function output(array $json, array $resources, array $settings, array $pages): int
    {
        if ($this->option('json') === true) {
            $this->line(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        foreach ([...$resources, ...$settings] as $section) {
            $this->printSection($section);
        }

        foreach ($pages as $page) {
            $this->components->twoColumnDetail(
                __('fastleo::console.inspect_page', ['label' => $this->text($page['label'] ?? ''), 'slug' => $this->text($page['slug'] ?? '')]),
                $this->text($page['view'] ?? ''),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Заголовок раздела со сведениями и таблица его полей.
     *
     * @param  array<string, mixed>  $section
     */
    private function printSection(array $section): void
    {
        $this->components->info(__('fastleo::console.inspect_section', ['label' => $this->text($section['label'] ?? ''), 'slug' => $this->text($section['slug'] ?? ''), 'class' => $this->text($section['class'] ?? '')]));

        if (isset($section['error'])) {
            $this->components->error($this->text($section['error']));

            return;
        }

        foreach (['model', 'table', 'policy', 'sort_column', 'tree_column'] as $key) {
            if (isset($section[$key]) && $section[$key] !== '') {
                $this->components->twoColumnDetail($key, $this->text($section[$key]));
            }
        }

        $rows = [];

        foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $rules = is_array($field['rules'] ?? null) ? $field['rules'] : [];
            $first = array_key_first($rules);
            $rows[] = [
                $this->text($field['name'] ?? ''),
                $this->text($field['type'] ?? ''),
                ($field['required'] ?? false) === true ? '✓' : '',
                $first === null || ! is_array($rules[$first]) ? '' : implode('|', array_map($this->text(...), $rules[$first])),
                $this->text($field['related_model'] ?? ''),
            ];
        }

        $this->table(['field', 'type', 'required', 'rules', 'related'], $rows);

        foreach (['filters', 'actions'] as $key) {
            $names = [];

            foreach (is_array($section[$key] ?? null) ? $section[$key] : [] as $item) {
                if (is_array($item)) {
                    $names[] = $this->text($item['key'] ?? $item['name'] ?? '');
                }
            }

            if ($names !== []) {
                $this->components->twoColumnDetail($key, implode(', ', $names));
            }
        }
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
