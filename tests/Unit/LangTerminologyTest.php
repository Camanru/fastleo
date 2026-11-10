<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Тексты интерфейса нейтральны: панель — не только «админка сайта», в ней работают менеджеры и другие роли.
 */
it('keeps the words «админ…» out of the interface texts except where they name a real administrator', function (): void {
    // Администратор мессенджера (канала) и SoftNetica, а также название готовой роли — это настоящие администраторы.
    $allowed = [
        'messengers.not_admin',
        'messengers.rights_missing',
        'publishing.channel_chat_help',
        'roles.preset_admin',
        'softnetica.token_help',
    ];

    $found = [];

    foreach (glob(dirname(__DIR__, 2).'/resources/lang/ru/*.php') ?: [] as $file) {
        foreach (Arr::dot(require $file) as $key => $text) {
            $name = basename($file, '.php').'.'.$key;

            if (is_string($text) && mb_stripos($text, 'админ') !== false && ! in_array($name, $allowed, true)) {
                $found[] = $name;
            }
        }
    }

    expect($found)->toBe([]);
});
