<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Illuminate\Support\Str;

/**
 * Собирает файлы для нейросетей из `docs/` — единого источника знаний:
 * skill для Claude Code, гайдлайны Laravel Boost и `docs/llms.txt`.
 * Руками эти файлы не правятся: `composer docs:build` пересобирает их, а тест следит, чтобы они были свежими.
 */
final readonly class DocsBuilder
{
    /** Описание skill: по нему Claude решает, когда его подключать. */
    private const string SKILL_DESCRIPTION = 'Use this skill when creating or changing sections (CRUD) of the Fastleo admin panel in a Laravel site: resources in app/Fastleo, fields, filters, actions, dashboard widgets, settings pages, and the migration, model and test for a new section; also for the fastleo:* artisan commands (make-resource, inspect, doctor). Trigger on: fastleo, админка, раздел админки, ресурс, поле формы, фильтр, действие, виджет.';

    /** Краткое описание пакета для начала skill и llms.txt. */
    private const string SUMMARY = 'Fastleo — админ-панель для сайтов на Laravel 13. Раздел админки (CRUD модели) описывается одним классом-ресурсом в app/Fastleo с типизированными полями; запись идёт через Eloquent, права задаёт Policy модели.';

    /** Где лежат docs относительно корня сайта. */
    private const string SITE_DOCS = 'vendor/camanru/fastleo/docs/';

    public function __construct(private string $root) {}

    /**
     * Все собираемые файлы: путь от корня пакета → содержимое.
     *
     * @return array<string, string>
     */
    public function build(): array
    {
        return [
            'resources/boost/skills/fastleo/SKILL.md' => $this->skill(),
            'resources/boost/guidelines/core.blade.php' => $this->guideline(),
            'docs/llms.txt' => $this->llms(),
        ];
    }

    /**
     * Skill: порядок работы, правила, ошибки, команды, таблица полей и указатель docs.
     */
    private function skill(): string
    {
        $claude = $this->doc('claude.md');
        $sections = $this->sections($claude);
        unset($sections['Подключение']);

        $body = [];

        foreach ($sections as $heading => $text) {
            $body[] = '## '.$heading."\n\n".$text;
        }

        $body[] = "## Поля\n\n".$this->fieldsTable();
        $body[] = "## Документация\n\nПодробности — в docs пакета (читайте нужный файл целиком):\n\n".implode("\n", $this->docIndex(self::SITE_DOCS));

        $text = "# Fastleo — разделы админки\n\n".self::SUMMARY."\n\n".implode("\n\n", $body);

        return "---\nname: fastleo\ndescription: \"".self::SKILL_DESCRIPTION."\"\nlicense: MIT\nmetadata:\n  author: camanru\n---\n"
            .$this->links($text, 'claude.md', self::SITE_DOCS)."\n";
    }

    /**
     * Гайдлайны Boost: коротко — порядок работы и правила, остальное в skill.
     */
    private function guideline(): string
    {
        $sections = $this->sections($this->doc('claude.md'));
        $steps = [];

        foreach (explode("\n", $sections['Порядок работы']) as $line) {
            if (str_starts_with($line, 'Пример') || str_starts_with($line, '```')) {
                break;
            }

            $steps[] = $line;
        }

        $text = "## Fastleo (админка сайта)\n\n"
            .self::SUMMARY."\n\n"
            ."### Порядок работы\n\n".trim(implode("\n", $steps))."\n\n"
            ."### Правила\n\n".$sections['Правила']."\n\n"
            .'Подробности: skill `fastleo` и docs в `'.self::SITE_DOCS.'` (`php artisan fastleo:inspect --json` — текущая конфигурация сайта).';

        return "@verbatim\n".$this->links($text, 'claude.md', self::SITE_DOCS)."\n@endverbatim\n";
    }

    /**
     * Справочник по API для любых нейросетей: порядок работы, поля с методами и указатель docs.
     */
    private function llms(): string
    {
        $claude = $this->sections($this->doc('claude.md'));
        $out = ['# Fastleo', '> '.self::SUMMARY];
        $out[] = "## Порядок работы\n\n".$claude['Порядок работы'];
        $out[] = "## Правила\n\n".$claude['Правила'];
        $out[] = "## Поля\n\n".implode("\n\n", $this->fieldEntries());
        $out[] = "## Документация\n\n".implode("\n", $this->docIndex(''));

        return $this->links(implode("\n\n", $out), 'claude.md', '')."\n";
    }

    /**
     * Описания полей: подпись, назначение, пример и таблица методов.
     *
     * @return list<string>
     */
    private function fieldEntries(): array
    {
        $entries = [];

        foreach ($this->fieldRows() as $row) {
            $markdown = $this->doc($row['file']);
            $entry = '### '.$row['name'].' — '.$row['purpose'].' (колонка '.$row['column'].')'."\n\n".$this->lead($markdown);
            $example = $this->firstCodeBlock($markdown);
            $methods = $this->firstTable($markdown);

            $entries[] = $this->links($entry.($example === '' ? '' : "\n\n".$example).($methods === '' ? '' : "\n\n".$methods), $row['file'], '')."\n\nДетали: docs/".$row['file'];
        }

        return $entries;
    }

    /**
     * Строки таблицы «Типы» из docs/fields/README.md: имя, назначение, колонка, файл относительно docs.
     *
     * @return list<array{name: string, purpose: string, column: string, file: string}>
     */
    private function fieldRows(): array
    {
        $rows = [];

        foreach (explode("\n", $this->sections($this->doc('fields/README.md'))['Типы']) as $line) {
            if (preg_match('/^\| \[(\w+)\]\(([^)]+)\) \| (.+?) \| (.+?) \|$/', $line, $match) === 1) {
                $rows[] = ['name' => $match[1], 'purpose' => $match[3], 'column' => $match[4], 'file' => $this->resolve('fields', $match[2])];
            }
        }

        return $rows;
    }

    /**
     * Таблица «Типы» из docs/fields/README.md (ссылки пересчитываются при сборке).
     */
    private function fieldsTable(): string
    {
        return $this->links($this->sections($this->doc('fields/README.md'))['Типы'], 'fields/README.md', self::SITE_DOCS);
    }

    /**
     * Указатель docs: заголовок, ссылка и первое предложение.
     *
     * @return list<string>
     */
    private function docIndex(string $prefix): array
    {
        $files = glob($this->root.'/docs/*.md') ?: [];
        sort($files);
        $lines = [];

        foreach ($files as $file) {
            $name = basename($file);

            if ($name === 'contributing.md') {
                continue;
            }

            $markdown = $this->doc($name);
            $lines[] = '- ['.$this->title($markdown).']('.$prefix.$name.') — '.Str::limit($this->lead($markdown), 160, '…', true);
        }

        return $lines;
    }

    /**
     * Текст файла из docs.
     */
    private function doc(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents($this->root.'/docs/'.$file));
    }

    /**
     * Первый заголовок первого уровня.
     */
    private function title(string $markdown): string
    {
        return preg_match('/^# (.+)$/m', $markdown, $match) === 1 ? trim($match[1]) : '';
    }

    /**
     * Первый абзац после заголовка (в одну строку).
     */
    private function lead(string $markdown): string
    {
        $paragraph = [];

        foreach (explode("\n", $markdown) as $line) {
            if ($paragraph === [] && ($line === '' || str_starts_with($line, '#'))) {
                continue;
            }

            if ($line === '' || str_starts_with($line, '```') || str_starts_with($line, '#')) {
                break;
            }

            $paragraph[] = $line;
        }

        return trim(implode(' ', $paragraph));
    }

    /**
     * Разделы второго уровня: заголовок → текст (с подзаголовками и кодом).
     *
     * @return array<string, string>
     */
    private function sections(string $markdown): array
    {
        $sections = [];
        $current = null;
        $inCode = false;

        foreach (explode("\n", $markdown) as $line) {
            if (str_starts_with($line, '```')) {
                $inCode = ! $inCode;
            }

            if (! $inCode && str_starts_with($line, '## ')) {
                $current = trim(substr($line, 3));
                $sections[$current] = '';

                continue;
            }

            if ($current !== null) {
                $sections[$current] .= $line."\n";
            }
        }

        return array_map(trim(...), $sections);
    }

    /**
     * Первый блок кода с языком (вместе с ограждением).
     */
    private function firstCodeBlock(string $markdown): string
    {
        return preg_match('/^```\w+\n.*?\n```$/ms', $markdown, $match) === 1 ? $match[0] : '';
    }

    /**
     * Первая таблица файла.
     */
    private function firstTable(string $markdown): string
    {
        $table = [];

        foreach (explode("\n", $markdown) as $line) {
            if (str_starts_with($line, '|')) {
                $table[] = $line;
            } elseif ($table !== []) {
                break;
            }
        }

        return implode("\n", $table);
    }

    /**
     * Переписать относительные ссылки на файлы docs: `[x](fields/text.md)` → `[x](<префикс>fields/text.md)`.
     */
    private function links(string $markdown, string $from, string $prefix): string
    {
        $dir = dirname($from) === '.' ? '' : dirname($from);

        return (string) preg_replace_callback(
            '/\]\((?!https?:|#|\/)([^)\s#]+)(#[^)\s]*)?\)/',
            fn (array $match): string => $prefix !== '' && str_starts_with($match[1], $prefix) ? $match[0] : ']('.$prefix.$this->resolve($dir, $match[1]).($match[2] ?? '').')',
            $markdown,
        );
    }

    /**
     * Путь `$target` относительно папки `$dir` внутри docs без `./` и `../`.
     */
    private function resolve(string $dir, string $target): string
    {
        $parts = [];

        foreach (explode('/', ($dir === '' ? '' : $dir.'/').$target) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
