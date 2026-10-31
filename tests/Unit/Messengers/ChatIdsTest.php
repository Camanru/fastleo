<?php

declare(strict_types=1);

use Fastleo\Messengers\ChatIds;

it('reads the token from the field of the form that the site names', function (): void {
    $field = ChatIds::make('chat_id', 'Канал');

    expect($field->getTokenField())->toBe('token')
        ->and($field->tokenField('bot_token')->getTokenField())->toBe('bot_token')
        ->and($field->getMessengerField())->toBe('messenger');
});

it('is left out of exports never', function (): void {
    expect(ChatIds::make('recipients', 'Получатели')->isExportable())->toBeFalse();
});
