<?php

declare(strict_types=1);

use Fastleo\Support\Branding;

it('takes the admin name from the brand, then from the site name', function (): void {
    config(['fastleo.brand.name' => 'Магазин', 'app.name' => 'Сайт']);
    expect(Branding::name())->toBe('Магазин');

    config(['fastleo.brand.name' => null]);
    expect(Branding::name())->toBe('Сайт');
});

it('falls back to the site address from app.url, not to the package name, when neither the brand nor the site has a name', function (): void {
    config(['fastleo.brand.name' => '', 'app.name' => '', 'app.url' => 'https://shop.example']);

    expect(Branding::name())->toBe('shop.example');

    config(['app.url' => null]);

    expect(Branding::name())->toBe('localhost');
});
