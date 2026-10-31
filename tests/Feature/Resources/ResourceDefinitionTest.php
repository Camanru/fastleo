<?php

declare(strict_types=1);

use Fastleo\Related;
use Workbench\App\Fastleo\ArticleResource;
use Workbench\App\Fastleo\CategoryResource;
use Workbench\App\Models\Article;

it('collects the rules of every writable field of the section', function (): void {
    $rules = new ArticleResource()->rules(null);

    expect($rules)->toHaveKeys(['title', 'status'])
        ->and($rules['title'])->toContain('required')
        ->and($rules)->not->toHaveKeys(['id', 'created_at', 'deleted_at']);
});

it('takes the rules of an existing record into account', function (): void {
    $article = Article::factory()->create();

    expect(new ArticleResource()->rules($article))->toHaveKeys(['title', 'status']);
});

it('refuses a related table named by something that is not a relation method name', function (string $name): void {
    expect(fn (): Related => Related::make($name, CategoryResource::class))->toThrow(InvalidArgumentException::class);
})->with(['starts with a digit' => '1articles', 'has a space' => 'my articles', 'has a dash' => 'my-articles', 'empty' => '']);
