<?php

declare(strict_types=1);

use Fastleo\Fastleo;
use Fastleo\Support\Menu;

beforeEach(function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);
    useFixtureResources('Menu');
    // Порядок групп демо-сайта (workbench) здесь не нужен: каждый тест задаёт свой.
    config(['fastleo.logs.enabled' => false, 'fastleo.menu.groups' => []]);
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

    expect(array_column($groups, 'label'))->toBe([null, 'Сайт', 'Контент', 'Медиа'])
        ->and($groups[0]['items'][0])->toMatchArray(['label' => 'Помощь', 'icon' => 'link'])
        ->and($groups[1]['items'][0])->toMatchArray(['label' => 'Открыть сайт', 'url' => '/', 'icon' => 'globe'])
        ->and($groups[2]['items'][0])->toMatchArray(['label' => 'Новости', 'url' => route('fastleo.resources.index', 'news')]);
});

it('puts groups missing from the config after the listed ones in alphabetical order', function (): void {
    config(['fastleo.menu.links' => [
        ['label' => 'Я', 'url' => '/a', 'group' => 'Яблоки'],
        ['label' => 'А', 'url' => '/b', 'group' => 'Арбузы'],
    ]]);

    expect(array_column(Menu::groups(), 'label'))->toBe(['Арбузы', 'Контент', 'Медиа', 'Яблоки']);
});

it('puts files into the media group and the error log into the system group', function (): void {
    config(['fastleo.logs.enabled' => true]);
    $groups = collect(Menu::groups())->keyBy('label');

    expect(array_column($groups['Медиа']['items'], 'label'))->toBe(['Файлы'])
        ->and(array_column($groups['Система']['items'], 'label'))->toContain('Журнал ошибок');
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

it('renders every named group with a toggle and marks the group of the open section', function (): void {
    $response = $this->get(route('fastleo.resources.index', 'news'));

    $html = (string) $response->assertOk()->getContent();

    expect(substr_count($html, 'data-menu-group="'))->toBe(2)
        ->and($html)->toMatch('/data-menu-group="Контент"\s+data-active/')
        ->and($html)->not->toMatch('/data-menu-group="Медиа"\s+data-active/')
        ->and($html)->toContain('aria-controls="fastleo-menu-group-1"')
        ->and($html)->toContain('id="fastleo-menu-group-1"');
});
