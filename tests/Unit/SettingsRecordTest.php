<?php

declare(strict_types=1);

use Fastleo\Support\SettingsRecord;
use Illuminate\Database\Eloquent\Model;

it('keeps only the values of the page fields', function (): void {
    $record = SettingsRecord::from(['phone' => '+7 900', 'foreign' => 'x'], ['phone', 'email']);

    expect($record->getAttribute('phone'))->toBe('+7 900')
        ->and($record->getAttributes())->toBe(['phone' => '+7 900']);
});

it('never treats a name as a relation of the model', function (): void {
    SettingsRecord::resolveRelationUsing('related_pages', fn (SettingsRecord $record) => $record->hasMany(Model::class));
    $record = SettingsRecord::from(['phone' => '+7 900'], ['phone']);

    expect($record->isRelation('related_pages'))->toBeFalse()
        ->and($record->getAttribute('related_pages'))->toBeNull();
});
