<?php

declare(strict_types=1);

use Fastleo\Fields\Field;
use Workbench\App\Support\DocsBuilder;

/**
 * Корень пакета.
 */
function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

it('keeps the files built from docs up to date', function (string $path, string $content): void {
    expect(file_get_contents(packageRoot().'/'.$path))
        ->toBe($content, "{$path} устарел — выполните composer docs:build");
})->with(fn (): array => collect(new DocsBuilder(packageRoot())->build())->map(fn (string $content, string $path): array => [$path, $content])->all());

it('describes every field type in the docs', function (): void {
    $readme = (string) file_get_contents(packageRoot().'/docs/fields/README.md');
    $classes = collect(glob(packageRoot().'/src/Fields/*.php') ?: [])
        ->map(fn (string $file): string => 'Fastleo\\Fields\\'.basename($file, '.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Field::class) && ! new ReflectionClass($class)->isAbstract());

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect($readme)->toContain('['.class_basename($class).'](');
    }
});

it('links every field row of the docs to an existing file', function (): void {
    preg_match_all('/^\| \[\w+\]\(([^)]+)\) \|/m', (string) file_get_contents(packageRoot().'/docs/fields/README.md'), $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $link) {
        expect(realpath(packageRoot().'/docs/fields/'.$link))->toBeString();
    }
});

it('gives the skill a name, a description and a short guideline', function (): void {
    $skill = (string) file_get_contents(packageRoot().'/resources/boost/skills/fastleo/SKILL.md');
    $guideline = (string) file_get_contents(packageRoot().'/resources/boost/guidelines/core.blade.php');

    expect($skill)->toStartWith("---\nname: fastleo\ndescription: \"")
        ->and($skill)->toContain('fastleo:doctor', 'vendor/camanru/fastleo/docs/fields/text.md')
        ->and(substr_count($guideline, "\n"))->toBeLessThanOrEqual(40);
});

it('points every link of the skill and llms.txt to an existing docs file', function (): void {
    $skill = (string) file_get_contents(packageRoot().'/resources/boost/skills/fastleo/SKILL.md');
    $llms = (string) file_get_contents(packageRoot().'/docs/llms.txt');

    preg_match_all('/\]\(vendor\/camanru\/fastleo\/docs\/([^)#]+)/', $skill, $skillLinks);
    preg_match_all('/\]\((?!https?:|#)([^)#]+\.md)/', $llms, $llmsLinks);

    expect($skillLinks[1])->not->toBeEmpty()
        ->and($llmsLinks[1])->not->toBeEmpty()
        ->and($skill)->not->toContain('docs/vendor/camanru', '](#')
        ->and($llms)->not->toContain('](#');

    foreach ([...$skillLinks[1], ...$llmsLinks[1]] as $file) {
        expect(packageRoot().'/docs/'.$file)->toBeFile();
    }
});

it('keeps every FAQ question unique within its file so that its anchor is stable', function (): void {
    $files = array_filter(glob(packageRoot().'/docs/faq/*.md') ?: [], fn (string $file): bool => basename($file) !== 'README.md');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $markdown = (string) preg_replace('/```.*?```/s', '', (string) file_get_contents($file));
        preg_match_all('/^### (.+)$/m', $markdown, $questions);

        expect($markdown)->toStartWith('# FAQ: ')
            ->and($questions[1])->not->toBeEmpty()
            ->and(array_unique($questions[1]))->toHaveCount(count($questions[1]));
    }
});
