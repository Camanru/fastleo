<?php

declare(strict_types=1);

use Fastleo\Fields\RichText;
use Fastleo\Support\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

it('removes scripts, handlers and dangerous links from the editor html', function (string $html): void {
    $clean = (string) HtmlSanitizer::clean($html.'<p>текст</p>');

    expect(strtolower($clean))->not->toContain('<script')
        ->not->toContain('javascript')
        ->not->toContain('onerror')
        ->not->toContain('onload')
        ->not->toContain('onclick')
        ->not->toContain('<svg')
        ->not->toContain('<style')
        ->not->toContain('<form')
        ->not->toContain('data:')
        ->toContain('<p>текст</p>');
})->with([
    'script' => '<script>alert(1)</script>',
    'img onerror' => '<img src=x onerror=alert(1)>',
    'svg onload' => '<svg onload=alert(1)><circle/></svg>',
    'javascript link' => '<a href="javascript:alert(1)">x</a>',
    'tab inside the scheme' => '<a href="java&#9;script:alert(1)">x</a>',
    'entity in the scheme' => '<a href="&#106;avascript:alert(1)">x</a>',
    'upper case scheme' => '<a href="JaVaScRiPt:alert(1)">x</a>',
    'onclick on a paragraph' => '<p onclick="alert(1)">x</p>',
    'style tag' => '<style>body{display:none}</style>',
    'form' => '<form action="https://evil.example"><input name="password"></form>',
    'data image' => '<img src="data:image/png;base64,AAAA">',
    'iframe to a site' => '<iframe src="https://evil.example/phish"></iframe>',
    'iframe javascript' => '<iframe src="javascript:alert(1)"></iframe>',
]);

it('keeps the structure of the text', function (): void {
    $html = '<h2>Заголовок</h2><p><strong>Жирный</strong> и <em>курсив</em>, <a href="/page" target="_blank">ссылка</a>, <a href="mailto:a@b.ru">почта</a></p>'
        .'<ul><li>пункт</li></ul><ol start="3"><li>три</li></ol><blockquote>цитата</blockquote>'
        .'<figure><img src="/storage/uploads/a.jpg" alt="Фото" width="400" height="300"><figcaption>Подпись</figcaption></figure>'
        .'<table><tbody><tr><th scope="col">А</th><td colspan="2">Б</td></tr></tbody></table>';

    expect(HtmlSanitizer::clean($html))->toBe(
        '<h2>Заголовок</h2><p><strong>Жирный</strong> и <em>курсив</em>, <a href="/page" target="_blank" rel="noopener">ссылка</a>, <a href="mailto:a&#64;b.ru" rel="noopener">почта</a></p>'
        .'<ul><li>пункт</li></ul><ol start="3"><li>три</li></ol><blockquote>цитата</blockquote>'
        .'<figure><img src="/storage/uploads/a.jpg" alt="Фото" width="400" height="300" /><figcaption>Подпись</figcaption></figure>'
        .'<table><tbody><tr><th scope="col">А</th><td colspan="2">Б</td></tr></tbody></table>',
    );
});

it('drops a style attribute without text alignment', function (): void {
    expect(HtmlSanitizer::clean('<p style="color: red; font-size: 40px">x</p>'))->toBe('<p>x</p>');
});

it('keeps only the text alignment from styles', function (): void {
    expect(HtmlSanitizer::clean('<p style="color: red; TEXT-ALIGN: Center; font-size: 40px">x</p><td style="background:url(javascript:x)">y</td>'))
        ->toBe('<p style="text-align: center">x</p>y');
});

it('keeps videos of known players only', function (): void {
    expect(HtmlSanitizer::clean('<iframe src="https://www.youtube.com/embed/abc" width="560" allowfullscreen></iframe>'))
        ->toBe('<iframe src="https://www.youtube.com/embed/abc" width="560" allowfullscreen></iframe>')
        ->and(HtmlSanitizer::clean('<iframe src="https://rutube.ru/play/embed/123"></iframe><iframe src="https://vk.com/video_ext.php?oid=1&id=2"></iframe>'))
        ->toBe('<iframe src="https://rutube.ru/play/embed/123"></iframe><iframe src="https://vk.com/video_ext.php?oid&#61;1&amp;id&#61;2"></iframe>')
        ->and(HtmlSanitizer::clean('<iframe src="http://www.youtube.com/embed/abc"></iframe><p>x</p>'))->toBe('<iframe></iframe><p>x</p>');
});

it('does not let a player ask for the camera, the microphone or the location', function (): void {
    expect(HtmlSanitizer::clean('<iframe src="https://www.youtube.com/embed/abc" allow="camera; microphone; geolocation" allowfullscreen></iframe>'))
        ->toBe('<iframe src="https://www.youtube.com/embed/abc" allowfullscreen></iframe>');
});

it('unwraps unknown tags but keeps their text', function (): void {
    expect(HtmlSanitizer::clean('<div class="x"><span style="font-size:20px">Текст</span></div><h1>Главный</h1>'))->toBe('ТекстГлавный');
});

it('gives null for an empty text and is stable when cleaned again', function (): void {
    $html = '<p style="text-align: right" onclick="x">a &amp; b<br></p><img src="/a.jpg" alt="">';

    expect(HtmlSanitizer::clean('<p><br></p>'))->toBeNull()
        ->and(HtmlSanitizer::clean("<p>&nbsp;</p>\n"))->toBeNull()
        ->and(HtmlSanitizer::clean(null))->toBeNull()
        ->and(HtmlSanitizer::clean('<p><img src="/a.jpg" alt=""></p>'))->toBe('<p><img src="/a.jpg" alt /></p>')
        ->and(HtmlSanitizer::clean(HtmlSanitizer::clean($html)))->toBe(HtmlSanitizer::clean($html));
});

it('lets the site extend the profile', function (): void {
    expect(HtmlSanitizer::clean('<span class="badge">новое</span>', fn (HtmlSanitizerConfig $config): HtmlSanitizerConfig => $config->allowElement('span', ['class'])))
        ->toBe('<span class="badge">новое</span>');
});

it('keeps the tags, attributes and players the site allows in fastleo.rich_text', function (): void {
    config()->set('fastleo.rich_text', [
        'tags' => ['div', 'span', 'p'],
        'attributes' => ['class', 'role', 'align', 'data-lpu'],
        'iframe_hosts' => ['https://prodoctorov.ru/widget/'],
    ]);

    $html = '<div class="uk-h3" role="note" data-lpu="123" align="center"><span class="badge">новое</span><p style="color: red; text-align: right">x</p></div>'
        .'<iframe src="https://prodoctorov.ru/widget/rating/?id=1" width="300"></iframe><iframe src="https://prodoctorov.ru.evil.example/widget/"></iframe>';

    expect(HtmlSanitizer::clean($html))->toBe(
        '<div class="uk-h3" role="note" data-lpu="123" align="center"><span class="badge">новое</span><p style="text-align: right">x</p></div>'
        .'<iframe src="https://prodoctorov.ru/widget/rating/?id&#61;1" width="300"></iframe><iframe></iframe>',
    );
});

it('still removes dangerous markup when the site allows more', function (string $html): void {
    config()->set('fastleo.rich_text', [
        'tags' => ['div', 'span'],
        'attributes' => ['class', 'role', 'data-x'],
        'iframe_hosts' => ['https://prodoctorov.ru/'],
        'styles' => true,
    ]);

    $clean = strtolower((string) HtmlSanitizer::clean($html.'<p>текст</p>'));

    expect($clean)->not->toContain('<script')
        ->not->toContain('javascript')
        ->not->toContain('onerror')
        ->not->toContain('onclick')
        ->not->toContain('<!--')
        ->not->toContain('evil.example')
        ->not->toContain('url(')
        ->toContain('<p>текст</p>');
})->with([
    'script' => '<script>alert(1)</script>',
    'handler on a div' => '<div class="a" onclick="alert(1)">x</div>',
    'handler on an image' => '<img src=x onerror=alert(1)>',
    'javascript link' => '<a href="javascript:alert(1)" class="a">x</a>',
    'comment' => '<div><!--><img src=x onerror=alert(1)>--></div>',
    'foreign iframe' => '<iframe src="https://evil.example/prodoctorov.ru/"></iframe>',
    'iframe srcdoc' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    'style with url' => '<div style="background: url(https://evil.example/x.png)">x</div>',
    'style with javascript' => '<div style="background: url(javascript:alert(1))">x</div>',
]);

it('keeps style but drops its dangerous parts when styles is enabled', function (): void {
    config()->set('fastleo.rich_text', ['tags' => ['div'], 'styles' => true]);

    expect(HtmlSanitizer::clean('<div style="display: none; COLOR: red; position: fixed; background: url(x.png); width: expression(alert(1))">x</div><p style="text-align: center; z-index: 9">y</p>'))
        ->toBe('<div style="display: none; color: red">x</div><p style="text-align: center; z-index: 9">y</p>');
});

it('rejects an unsafe rich_text setting', function (string $key, array $values): void {
    config()->set('fastleo.rich_text.'.$key, $values);

    expect(fn (): ?string => HtmlSanitizer::clean('<p>x</p>'))->toThrow(LogicException::class, 'fastleo.rich_text.'.$key);
})->with([
    'script tag' => ['tags', ['script']],
    'form tag' => ['tags', ['form']],
    'tag in upper case' => ['tags', ['Div']],
    'handler attribute' => ['attributes', ['onclick']],
    'alpine init' => ['attributes', ['x-init']],
    'alpine html' => ['attributes', ['x-html']],
    'htmx request' => ['attributes', ['hx-get']],
    'htmx in data' => ['attributes', ['data-hx-post']],
    'vue directive' => ['attributes', ['v-html']],
    'angular handler' => ['attributes', ['ng-click']],
    'srcdoc attribute' => ['attributes', ['srcdoc']],
    'style attribute' => ['attributes', ['style']],
    'attribute with a quote' => ['attributes', ['a"b']],
    'plain http player' => ['iframe_hosts', ['http://prodoctorov.ru/']],
    'host without a path end' => ['iframe_hosts', ['https://prodoctorov.ru']],
    'player with a quote' => ['iframe_hosts', ['https://prodoctorov.ru/a"onload=x/']],
    'not a string' => ['tags', [123]],
]);

it('takes the template of the nearest parent field when the site subclass has none', function (): void {
    $field = new class('content') extends RichText {};

    expect($field->view())->toBe('fastleo::fields.rich-text')
        ->and((new class('x') extends RichText
        {
            #[Override]
            public function type(): string
            {
                return 'site-rich-text';
            }
        })->view())->toBe('fastleo::fields.rich-text');
});

it('does not take a tag the site allowed for an empty text', function (): void {
    config()->set('fastleo.rich_text', ['tags' => ['div', 'video'], 'attributes' => ['class', 'data-lpu']]);

    expect(HtmlSanitizer::clean('<div class="pd_widget" data-lpu="123"></div>'))->toBe('<div class="pd_widget" data-lpu="123"></div>')
        ->and(HtmlSanitizer::clean('<video></video>'))->toBe('<video></video>')
        ->and(HtmlSanitizer::clean('<p><br></p>'))->toBeNull();
});

it('still treats an empty div as an empty text when the site did not allow it', function (): void {
    expect(HtmlSanitizer::clean('<div class="x" data-lpu="1"></div>'))->toBeNull();
});

it('skips empty and malformed declarations when styles is enabled', function (): void {
    config()->set('fastleo.rich_text', ['tags' => ['div'], 'styles' => true]);

    expect(HtmlSanitizer::clean('<div style="color: red;; just words; 12px; display: none;">x</div>'))
        ->toBe('<div style="color: red; display: none">x</div>');
});
