<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\Menu;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    useFixtureResources('Menu');
    config(['fastleo.logs.enabled' => false]);
});

it('orders groups as configured and puts links without a group first', function (): void {
    config([
        'fastleo.menu.groups' => ['Сайт', 'Контент'],
        'fastleo.menu.links' => [
            ['label' => 'Открыть сайт', 'url' => '/', 'icon' => 'globe', 'group' => 'Сайт'],
            ['label' => 'Помощь', 'url' => 'https://fastleo.ru'],
            ['label' => 'Без адреса'],
            'не массив',
        ],
    ]);

    $groups = Menu::groups();

    expect(array_column($groups, 'label'))->toBe([null, 'Сайт', 'Контент', 'Медиа', 'Система'])
        ->and($groups[0]['items'][0])->toMatchArray(['label' => 'Помощь', 'icon' => 'link'])
        ->and($groups[1]['items'][0])->toMatchArray(['label' => 'Открыть сайт', 'url' => '/', 'icon' => 'globe'])
        ->and($groups[2]['items'][0])->toMatchArray(['label' => 'Новости', 'url' => route('fastleo.resources.index', 'news')]);
});

it('puts groups missing from the config after the listed ones in alphabetical order', function (): void {
    config(['fastleo.menu.links' => [
        ['label' => 'Я', 'url' => '/a', 'group' => 'Яблоки'],
        ['label' => 'А', 'url' => '/b', 'group' => 'Арбузы'],
    ]]);

    expect(array_column(Menu::groups(), 'label'))->toBe(['Арбузы', 'Контент', 'Медиа', 'Система', 'Яблоки']);
});

it('puts files into the media group and the change log into the system group', function (): void {
    $groups = collect(Menu::groups())->keyBy('label');

    expect(array_column($groups['Медиа']['items'], 'label'))->toBe(['Файлы'])
        ->and(array_column($groups['Система']['items'], 'label'))->toContain('Журнал изменений');
});

it('orders items inside a group by position, then by label', function (): void {
    config(['fastleo.menu.links' => [
        ['label' => 'Последний', 'url' => '/z', 'group' => 'Контент', 'position' => 99],
        ['label' => 'Первый', 'url' => '/a', 'group' => 'Контент', 'position' => 1],
    ]]);

    expect(array_column(Menu::groups()[0]['items'], 'label'))->toBe(['Первый', 'Новости', 'Документы', 'Последний']);
});

it('marks the open section as active', function (): void {
    $this->get(route('fastleo.resources.index', 'documents'))
        ->assertOk()
        ->assertSee('aria-current="page"', false);

    expect(array_column(Menu::groups()[0]['items'], 'active', 'label'))->toBe(['Новости' => false, 'Документы' => true]);
});

it('shows no badge for zero', function (): void {
    expect(Menu::groups()[0]['items'][0])->toMatchArray(['label' => 'Новости', 'badge' => null]);
});
