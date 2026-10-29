<?php

declare(strict_types=1);

use Fastleo\Fastleo;

it('turns markdown into html for the preview tab', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->postJson(route('fastleo.markdown'), ['text' => "# Заголовок\n\n- пункт"])
        ->assertOk()
        ->assertJson(['html' => "<h1>Заголовок</h1>\n<ul>\n<li>пункт</li>\n</ul>\n"]);
});

it('escapes html and drops unsafe links in the preview', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);

    $html = $this->postJson(route('fastleo.markdown'), ['text' => '<img src=x onerror=alert(1)> [x](javascript:alert(1))'])
        ->assertOk()
        ->json('html');

    expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->not->toContain('<img')
        ->not->toContain('javascript:');
});

it('answers an empty preview for a non-text value', function (): void {
    $this->actingAs(admin(), Fastleo::GUARD);

    $this->postJson(route('fastleo.markdown'), ['text' => ['a']])->assertOk()->assertJson(['html' => '']);
});

it('does not render markdown for a guest', function (): void {
    $this->postJson(route('fastleo.markdown'), ['text' => '# x'])->assertUnauthorized();
});
