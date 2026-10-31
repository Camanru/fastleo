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
    private const string SKILL_DESCRIPTION = 'Use this skill when creating or changing sections (CRUD) of the Fastleo admin panel in a Laravel site: resources in app/Fastleo, fields, filters, actions, dashboard widgets, settings pages, and the migration, model and test for a new section; also for menu items and counters, related tables on a record card, inline editing, computed columns, list filters, report/statistics pages and CRM-style tasks (clients, requests, tasks, leads); also for the fastleo:* artisan commands (make-resource, inspect, doctor). Trigger on: fastleo, админка, раздел админки, ресурс, поле формы, фильтр, действие, виджет, меню, счётчик, связанные таблицы, отчёт, статистика, CRM.';

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
            'docs/faq/README.md' => $this->faqReadme(),
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
            .'Меню и счётчики, связанные таблицы, правка в списке, фильтры, действия, отчёты и рецепты CRM уже есть в пакете — таблица «Что выбрать» в skill `fastleo`. '
            .'Подробности: skill `fastleo` и docs в `'.self::SITE_DOCS.'` (`php artisan fastleo:inspect --json` — текущая конфигурация сайта).';

        return "@verbatim\n".$this->links($text, 'claude.md', self::SITE_DOCS)."\n@endverbatim\n";
    }

    /**
     * Справочник по API для любых нейросетей: порядок работы, правила, «что выбрать», поля с методами и указатель docs.
     */
    private function llms(): string
    {
        $claude = $this->sections($this->doc('claude.md'));
        $out = ['# Fastleo', '> '.self::SUMMARY];
        $out[] = "## Порядок работы\n\n".$claude['Порядок работы'];
        $out[] = "## Правила\n\n".$claude['Правила'];
        $out[] = "## Что выбрать\n\n".$claude['Что выбрать'];
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

            if (in_array($name, ['contributing.md', 'README.md'], true)) {
                continue;
            }

            $markdown = $this->doc($name);
            $lines[] = '- ['.$this->title($markdown).']('.$prefix.$name.') — '.Str::limit($this->lead($markdown), 160, '…', true);
        }

        return $lines;
    }

    /**
     * Оглавление FAQ (docs/faq/README.md): файлы по темам и все вопросы со ссылками. Собирается из файлов FAQ.
     */
    private function faqReadme(): string
    {
        $out = ['# FAQ', 'Готовые решения практических задач: вопрос → что сделать и код, который можно скопировать в сайт. Ниже — все вопросы по темам.'];

        foreach ($this->faqFiles() as $file) {
            $out[] = '## ['.$file['title'].']('.$file['name'].")\n\n".$file['lead'];

            foreach ($file['groups'] as $group => $questions) {
                $out[] = '**'.$group."**\n\n".implode("\n", array_map(fn (string $question): string => '- ['.$question.']('.$file['name'].'#'.$this->slug($question).')', $questions));
            }
        }

        return implode("\n\n", $out)."\n";
    }

    /**
     * Файлы FAQ (кроме оглавления): имя, тема, описание и вопросы по группам.
     *
     * @return list<array{name: string, title: string, lead: string, groups: array<string, list<string>>}>
     */
    private function faqFiles(): array
    {
        $files = glob($this->root.'/docs/faq/*.md') ?: [];
        sort($files);
        $out = [];

        foreach ($files as $file) {
            $name = basename($file);

            if ($name === 'README.md') {
                continue;
            }

            $markdown = $this->doc('faq/'.$name);
            $lead = (string) preg_replace('/\s*Все вопросы — \[[^\]]*\]\([^)]*\)\.?/u', '', $this->lead($markdown));
            $groups = [];
            $group = '';
            $inCode = false;

            foreach (explode("\n", $markdown) as $line) {
                if (str_starts_with($line, '```')) {
                    $inCode = ! $inCode;
                } elseif (! $inCode && str_starts_with($line, '## ')) {
                    $group = trim(substr($line, 3));
                } elseif (! $inCode && str_starts_with($line, '### ')) {
                    $groups[$group][] = trim(substr($line, 4));
                }
            }

            $out[] = ['name' => $name, 'title' => (string) preg_replace('/^FAQ:\s*/u', '', $this->title($markdown)), 'lead' => trim($lead), 'groups' => $groups];
        }

        return $out;
    }

    /**
     * Якорь заголовка, как его строит GitHub: строчные буквы, без знаков препинания, пробелы — дефисы.
     */
    private function slug(string $heading): string
    {
        return str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N}_\- ]/u', '', mb_strtolower($heading)));
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
     * Переписать относительные ссылки на файлы docs: `[x](fields/text.md)` → `[x](<префикс>fields/text.md)`,
     * а якорь своей страницы — в ссылку на неё: `[x](#методы)` → `[x](<префикс>fields/text.md#методы)`.
     */
    private function links(string $markdown, string $from, string $prefix): string
    {
        $dir = dirname($from) === '.' ? '' : dirname($from);
        $markdown = str_replace('](#', ']('.basename($from).'#', $markdown);

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
