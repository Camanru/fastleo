<?php

declare(strict_types=1);

use Fastleo\Support\Icons;
use Illuminate\Support\Facades\Blade;

it('renders a Lucide icon with the given attributes', function (): void {
    $html = Blade::render('<x-fastleo::icon name="house" class="size-5" />');

    expect($html)
        ->toStartWith('<svg aria-hidden="true" class="size-5" xmlns="http://www.w3.org/2000/svg"')
        ->toContain('viewBox="0 0 24 24"')
        ->toEndWith("</svg>\n");
});

it('rejects names that could leave the icons folder', function (string $name): void {
    Icons::svg($name);
})->with(['../composer', 'house.svg', 'House', '-house', ''])
    ->throws(InvalidArgumentException::class, 'Недопустимое имя иконки');

it('reports an unknown icon with a link to the list', function (): void {
    Icons::svg('no-such-icon');
})->throws(InvalidArgumentException::class, 'https://lucide.dev/icons');
