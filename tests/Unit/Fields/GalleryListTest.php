<?php

declare(strict_types=1);

use Fastleo\Fields\Gallery;
use Workbench\App\Models\Article;

/**
 * HTML ячейки списка.
 */
function galleryCell(Gallery $field, Article $article): string
{
    return (string) $field->listDisplay($article);
}

it('shows the first thumbnails of a gallery in the list and the total when there are more', function (): void {
    // Подпись берётся на языке запроса админки: вне запроса язык приложения задаём сами.
    app()->setLocale('ru');
    $few = new Article(['gallery' => ['uploads/a.jpg', 'uploads/b.jpg']]);
    $many = new Article(['gallery' => ['uploads/a.jpg', 'uploads/b.jpg', 'uploads/c.jpg', 'uploads/d.jpg', 'uploads/e.jpg']]);

    expect(substr_count(galleryCell(Gallery::make('gallery'), $few), '<img'))->toBe(2)
        ->and(galleryCell(Gallery::make('gallery'), $few))->not->toContain('шт.')
        ->and(substr_count(galleryCell(Gallery::make('gallery'), $many), '<img'))->toBe(3)
        ->and(galleryCell(Gallery::make('gallery'), $many))->toContain('5 шт.');
});

it('shows nothing for an empty gallery and the custom format when one is set', function (): void {
    expect(Gallery::make('gallery')->listDisplay(new Article))->toBeNull()
        ->and(Gallery::make('gallery')->formatUsing(fn (mixed $value): string => 'Свой вывод')->listDisplay(new Article(['gallery' => ['uploads/a.jpg']])))->toBe('Свой вывод');
});
