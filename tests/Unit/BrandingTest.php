<?php

declare(strict_types=1);

use Fastleo\Support\Branding;

it('takes the admin name from the brand, then from the site name', function (): void {
    config(['fastleo.brand.name' => 'Клиника', 'app.name' => 'Сайт']);
    expect(Branding::name())->toBe('Клиника');

    config(['fastleo.brand.name' => null]);
    expect(Branding::name())->toBe('Сайт');
});

it('falls back to Fastleo when neither the brand nor the site has a name', function (): void {
    config(['fastleo.brand.name' => '', 'app.name' => '']);

    expect(Branding::name())->toBe('Fastleo');
});
