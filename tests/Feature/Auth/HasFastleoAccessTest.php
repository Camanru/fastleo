<?php

declare(strict_types=1);

use Fastleo\Auth\FastleoUser;
use Fastleo\Auth\HasFastleoAccess;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Workbench\App\Models\User;

it('reads fastleo_admin as a boolean even when the site model has no cast for it', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['fastleo_admin' => true])->save();
    $model = new #[Table(name: 'users')] class extends Authenticatable implements FastleoUser
    {
        use HasFastleoAccess;
    };

    $stored = $model->newQuery()->findOrFail($admin->getKey());

    expect($stored->getAttribute('fastleo_admin'))->toBeTrue()
        ->and($stored->canAccessFastleo())->toBeTrue();
});

it('leaves a cast of fastleo_admin that the site model set itself', function (): void {
    $withProperty = new #[Table(name: 'users')] class extends Authenticatable implements FastleoUser
    {
        use HasFastleoAccess;

        protected $casts = ['fastleo_admin' => 'integer'];
    };
    $withMethod = new #[Table(name: 'users')] class extends Authenticatable implements FastleoUser
    {
        use HasFastleoAccess;

        protected function casts(): array
        {
            return ['fastleo_admin' => 'string'];
        }
    };

    expect($withProperty->getCasts()['fastleo_admin'])->toBe('integer')
        ->and($withMethod->getCasts()['fastleo_admin'])->toBe('string');
});
