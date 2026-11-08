<?php

declare(strict_types=1);

use Fastleo\Fields\RichText;
use Fastleo\Support\Html\DepthLimitedParser;
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
    'iframe path climbs out of the player' => '<iframe src="https://www.youtube.com/embed/../redirect?q=x"></iframe>',
    'iframe encoded dots' => '<iframe src="https://www.youtube.com/embed/%2e%2e/redirect"></iframe>',
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
        'attributes' => ['class', 'role', 'align', 'data-widget'],
        'iframe_hosts' => ['https://yandex.ru/map-widget/'],
    ]);

    $html = '<div class="uk-h3" role="note" data-widget="123" align="center"><span class="badge">новое</span><p style="color: red; text-align: right">x</p></div>'
        .'<iframe src="https://yandex.ru/map-widget/v1/?id=1" width="300"></iframe><iframe src="https://yandex.ru.evil.example/widget/"></iframe>';

    expect(HtmlSanitizer::clean($html))->toBe(
        '<div class="uk-h3" role="note" data-widget="123" align="center"><span class="badge">новое</span><p style="text-align: right">x</p></div>'
        .'<iframe src="https://yandex.ru/map-widget/v1/?id&#61;1" width="300"></iframe><iframe></iframe>',
    );
});

it('still removes dangerous markup when the site allows more', function (string $html): void {
    config()->set('fastleo.rich_text', [
        'tags' => ['div', 'span'],
        'attributes' => ['class', 'role', 'data-x'],
        'iframe_hosts' => ['https://yandex.ru/'],
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
    'foreign iframe' => '<iframe src="https://evil.example/yandex.ru/"></iframe>',
    'iframe srcdoc' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    'style with url' => '<div style="background: url(https://evil.example/x.png)">x</div>',
    'style with javascript' => '<div style="background: url(javascript:alert(1))">x</div>',
    'style with image-set' => '<div style="background: image-set(\'https://evil.example/p.png\' 1x)">x</div>',
]);

it('keeps style but drops its dangerous parts when styles is enabled', function (): void {
    config()->set('fastleo.rich_text', ['tags' => ['div'], 'styles' => true]);

    expect(HtmlSanitizer::clean('<div style="display: none; COLOR: red; position: fixed; background: url(x.png); width: expression(alert(1))">x</div><p style="text-align: center; z-index: 9">y</p>'))
        ->toBe('<div style="display: none; color: red">x</div><p style="text-align: center">y</p>');
});

it('drops absolute positioning that covers the page when styles is enabled', function (): void {
    config()->set('fastleo.rich_text', ['tags' => ['p'], 'styles' => true]);

    expect(HtmlSanitizer::clean('<p style="position: ABSOLUTE; top: 0; width: 100vw; color: red">x</p>'))
        ->toBe('<p style="top: 0; width: 100vw; color: red">x</p>');
});

it('drops everything that lays a block over the page when styles is enabled', function (string $style): void {
    config()->set('fastleo.rich_text', ['tags' => ['p'], 'styles' => true]);

    expect(HtmlSanitizer::clean('<p style="'.$style.'; color: red">x</p>'))->toBe('<p style="color: red">x</p>');
})->with([
    'relative with a shift' => 'position: relative; top: -500px',
    'relative' => 'position: Relative',
    'a layer order' => 'z-index: 2147483647',
    'a transform' => 'transform: translateY(-100vh)',
    'a translation' => 'translate: 0 -100vh',
    'a negative margin' => 'margin: -50vh 0 0 -50vw',
    'a negative side margin' => 'margin-left: -100vw',
    'a negative offset' => 'top: -10px',
    'a negative inset' => 'inset: -1px',
    'a negative calculation' => 'margin-top: calc(-1 * 100vh)',
    'a prefixed transform' => '-webkit-transform: translateY(-100vh)',
    'a prefixed transform of another browser' => '-moz-transform: translateY(-100vh)',
    'a movement along a path' => 'offset-path: ray(0deg)',
    'a distance along a path' => 'offset-distance: 100%',
    'a margin written as a calculation' => 'margin-top: calc(100vh * cos(180deg))',
    'a margin from a variable' => 'margin-top: var(--shift)',
    'an offset from a calculation' => 'left: calc(0px - 50vw)',
    'a shift with a flag' => 'margin: 0 !important',
]);

it('keeps the usual positive sizes, margins and static positioning when styles is enabled', function (): void {
    config()->set('fastleo.rich_text', ['tags' => ['p'], 'styles' => true]);

    expect(HtmlSanitizer::clean('<p style="position: static; width: 100vw; margin: 0 auto 1rem; padding-left: 2em; top: 0; margin-bottom: 1.5em; margin-inline: 10vh 5%">x</p>'))
        ->toBe('<p style="position: static; width: 100vw; margin: 0 auto 1rem; padding-left: 2em; top: 0; margin-bottom: 1.5em; margin-inline: 10vh 5%">x</p>');
});

it('keeps the lengths that Word writes and the modern units, and still refuses a minus in front of them', function (string $style, bool $kept): void {
    config()->set('fastleo.rich_text', ['tags' => ['p'], 'styles' => true]);

    $clean = HtmlSanitizer::clean('<p style="'.$style.'">x</p>');

    expect(str_contains((string) $clean, 'style="'.$style.'"'))->toBe($kept);
})->with([
    'a number that starts with a point' => ['margin: .5em', true],
    'a Word margin of a hundredth of a point' => ['margin-bottom: .0001pt', true],
    'a dynamic viewport unit' => ['margin-top: 2dvh', true],
    'a line height unit' => ['margin-left: 1lh', true],
    'a quarter millimetre' => ['margin-right: 4Q', true],
    'a container unit' => ['margin-top: 3cqw', true],
    'a point in front of a minus' => ['margin-top: -.5em', false],
    'a minus in front of a new unit' => ['margin-top: -2dvh', false],
    'a point and nothing else' => ['margin-top: .em', false],
]);

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
    'plain http player' => ['iframe_hosts', ['http://yandex.ru/']],
    'host without a path end' => ['iframe_hosts', ['https://yandex.ru']],
    'player with a quote' => ['iframe_hosts', ['https://yandex.ru/a"onload=x/']],
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
    config()->set('fastleo.rich_text', ['tags' => ['div', 'video'], 'attributes' => ['class', 'data-widget']]);

    expect(HtmlSanitizer::clean('<div class="map_widget" data-widget="123"></div>'))->toBe('<div class="map_widget" data-widget="123"></div>')
        ->and(HtmlSanitizer::clean('<video></video>'))->toBe('<video></video>')
        ->and(HtmlSanitizer::clean('<p><br></p>'))->toBeNull();
});

it('still treats an empty div as an empty text when the site did not allow it', function (): void {
    expect(HtmlSanitizer::clean('<div class="x" data-widget="1"></div>'))->toBeNull();
});

it('skips empty and malformed declarations when styles is enabled', function (): void {
    config()->set('fastleo.rich_text', ['tags' => ['div'], 'styles' => true]);

    expect(HtmlSanitizer::clean('<div style="color: red;; just words; 12px; display: none;">x</div>'))
        ->toBe('<div style="color: red; display: none">x</div>');
});

it('does not parse a text nested deeper than the limit: the sanitizer would crash the whole process', function (): void {
    $deep = str_repeat('<b>', 30_000).'x';

    expect(HtmlSanitizer::tooComplex($deep))->toBeTrue()
        ->and(HtmlSanitizer::clean($deep))->toBeNull()
        ->and(HtmlSanitizer::clean(str_repeat('<blockquote>', 20_000).'x'))->toBeNull();
});

it('allows nesting up to the limit and stops one level above it', function (): void {
    // Корень (body) — первый уровень, самый вложенный тег с текстом — последний.
    expect(HtmlSanitizer::tooComplex(str_repeat('<b>', 199).'x'))->toBeFalse()
        ->and(HtmlSanitizer::tooComplex(str_repeat('<b>', 200).'x'))->toBeTrue()
        ->and(HtmlSanitizer::clean(str_repeat('<blockquote>', 20).'x'))->not->toBeNull();
});

it('rejects thousands of nested div tags by the text alone: parsing them costs seconds that no execution limit interrupts', function (): void {
    $deep = str_repeat('<div>', 100_000).'x';
    $started = hrtime(true);

    expect(HtmlSanitizer::tooComplex($deep))->toBeTrue()
        ->and(HtmlSanitizer::clean($deep))->toBeNull()
        // Разбор такого текста занимал около пяти секунд, прикидка по тексту — тысячные доли.
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
});

it('rejects by the text alone every markup that makes the parser spend seconds, however it closes its tags', function (string $html): void {
    $started = hrtime(true);

    expect(HtmlSanitizer::tooComplex($html))->toBeTrue()
        ->and(HtmlSanitizer::clean($html))->toBeNull()
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
})->with([
    'closing tags that close nothing' => fn (): string => str_repeat('<div></x>', 110_000),
    'closing tags of another element' => fn (): string => str_repeat('<div></p>', 110_000),
    'foreign elements with stray closing tags' => fn (): string => '<svg>'.str_repeat('<g></x>', 100_000),
    'a closing tag inside a comment' => fn (): string => str_repeat('<div><!--</div>-->', 55_000),
    'a closing tag inside an attribute' => fn (): string => str_repeat('<div title="</div>">', 55_000),
    'a closing tag the parser skips inside a table cell' => fn (): string => str_repeat('<div><table><tr><td></div>', 40_000),
    'a closing tag inside a bogus comment' => fn (): string => str_repeat('<div><!</div>', 60_000),
    'formatting the parser creates again in every paragraph' => fn (): string => '<p>'.implode('', array_map(fn (int $i): string => '<b a='.$i.'>', range(1, 190))).str_repeat('</p><p>x', 3000),
]);

it('lets through the texts of real documents, however sloppy their markup', function (string $html): void {
    expect(HtmlSanitizer::tooComplex($html))->toBeFalse();
})->with([
    'paragraphs pasted from Word' => fn (): string => str_repeat('<p class=MsoNormal><span style="x">t<o:p></o:p></span></p>', 1500),
    'paragraphs with no closing tag' => fn (): string => str_repeat('<p>para text ', 1100),
    'list items with no closing tag' => fn (): string => '<ul>'.str_repeat('<li>x', 1100).'</ul>',
    'a big table' => fn (): string => '<table>'.str_repeat('<tr>'.str_repeat('<td>1</td>', 6).'</tr>', 400).'</table>',
    'a price list of five thousand rows, every tag closed' => fn (): string => '<table>'.str_repeat('<tr>'.str_repeat('<td>1</td>', 5).'</tr>', 4900).'</table>',
    'a drawing with many paths' => fn (): string => '<svg>'.str_repeat('<path d="M0 0"/>', 1100).'</svg>',
    'a less-than sign in the text' => fn (): string => str_repeat('1 < 2 and 3 <5, a &lt; b &amp;&amp; c &lt; d. ', 600),
    'bold words, italics and links' => fn (): string => str_repeat('<p>one <b>two</b> <i>three</i> <a href="/x">four</a></p>', 3500),
    'a few formatting tags left open' => fn (): string => '<b><i>'.str_repeat('<p>text</p>', 5000),
]);

it('counts the opening tags of the text and the closing ones separately, nothing else', function (): void {
    expect(DepthLimitedParser::markupTooHeavy(str_repeat('<br>', DepthLimitedParser::MAX_TAGS)))->toBeFalse()
        ->and(DepthLimitedParser::markupTooHeavy(str_repeat('<br>', DepthLimitedParser::MAX_TAGS + 1)))->toBeTrue()
        // Закрытые теги не считаются дважды: пары <p></p> на пределе проходят.
        ->and(DepthLimitedParser::markupTooHeavy(str_repeat('<p></p>', DepthLimitedParser::MAX_TAGS)))->toBeFalse()
        ->and(DepthLimitedParser::markupTooHeavy(str_repeat('<p></p>', DepthLimitedParser::MAX_TAGS + 1)))->toBeTrue()
        // Лишние закрывающие разбору почти ничего не стоят, но и их число ограничено: список совпадений занял бы память.
        ->and(DepthLimitedParser::markupTooHeavy(str_repeat('</x>', DepthLimitedParser::MAX_TAGS)))->toBeFalse()
        ->and(DepthLimitedParser::markupTooHeavy(str_repeat('</x>', DepthLimitedParser::MAX_TAGS + 1)))->toBeTrue()
        ->and(DepthLimitedParser::markupTooHeavy('a < b and 3 <5 <> text'))->toBeFalse();
});

it('estimates what the parser creates again for formatting that is left open, and forgets what was closed', function (): void {
    $open = fn (int $tags): string => str_repeat('<b>', $tags);

    // 100 открытых <b> и 1000 абзацев — ровно предел (100 000), на один абзац больше — отказ.
    expect(DepthLimitedParser::markupTooHeavy($open(100).str_repeat('<p>', 1000)))->toBeFalse()
        ->and(DepthLimitedParser::markupTooHeavy($open(100).str_repeat('<p>', 1001)))->toBeTrue()
        // Закрытое форматирование не считается; закрывающий тег чужого имени тоже ничего не закрывает.
        ->and(DepthLimitedParser::markupTooHeavy($open(100).str_repeat('</b>', 100).str_repeat('<p>', 3000)))->toBeFalse()
        ->and(DepthLimitedParser::markupTooHeavy($open(100).str_repeat('</i>', 100).str_repeat('<P>', 1001)))->toBeTrue()
        // Лишний закрывающий тег не уходит в минус и не прячет следующие открытые.
        ->and(DepthLimitedParser::markupTooHeavy(str_repeat('</b>', 500).$open(100).str_repeat('<p>', 1001)))->toBeTrue();
});

it('does not take an empty text or a text that is not utf-8 for too deep, but still refuses a heavy one in a wrong encoding', function (): void {
    expect(HtmlSanitizer::tooComplex(''))->toBeFalse()
        ->and(HtmlSanitizer::tooComplex("  \n"))->toBeFalse()
        ->and(HtmlSanitizer::tooComplex("\xB1".str_repeat('<b>', 500)))->toBeFalse()
        // Без этого проверка формы пропустила бы такой текст, а очистка молча сохранила бы пустоту.
        ->and(HtmlSanitizer::tooComplex("\xB1".str_repeat('<div>', DepthLimitedParser::MAX_TAGS + 1)))->toBeTrue();
});

it('refuses a text longer than the sanitizer reads without parsing it', function (): void {
    $limit = HtmlSanitizer::MAX_LENGTH * 4;

    expect(HtmlSanitizer::tooComplex(str_repeat('a', $limit + 1)))->toBeTrue()
        ->and(HtmlSanitizer::tooComplex(str_repeat('a', $limit)))->toBeFalse();
});

it('refuses a tag with more attributes than a living text has, whatever separates them, before the parser spends minutes on it', function (): void {
    $names = fn (int $count, string $separator): string => implode($separator, array_map(fn (int $number): string => 'a'.$number, range(1, $count)));
    $limit = DepthLimitedParser::MAX_ATTRIBUTES;

    expect(HtmlSanitizer::tooComplex('<p '.$names($limit, ' ').'>x</p>'))->toBeFalse()
        ->and(HtmlSanitizer::tooComplex('<p '.$names($limit + 1, ' ').'>x</p>'))->toBeTrue()
        // Через слэш, переводом строки и вплотную к закрывающей кавычке: разбор считает их отдельными атрибутами.
        ->and(HtmlSanitizer::tooComplex('<p a/'.$names($limit + 1, '/').'>x</p>'))->toBeTrue()
        ->and(HtmlSanitizer::tooComplex("<p\n".$names($limit + 1, "\n").'>x</p>'))->toBeTrue()
        ->and(HtmlSanitizer::tooComplex('<p '.implode('', array_map(fn (int $number): string => 'a'.$number.'="1"', range(1, $limit + 1))).'>x</p>'))->toBeTrue()
        // «>» внутри значения не закрывает тег, а имя может начинаться с «=».
        ->and(HtmlSanitizer::tooComplex('<p '.implode(' ', array_map(fn (int $number): string => 'a'.$number.'=">"', range(1, $limit + 1))).'>x</p>'))->toBeTrue()
        ->and(HtmlSanitizer::tooComplex('<p '.implode(' ', array_map(fn (int $number): string => '=a'.$number.'="1"', range(1, $limit + 1))).'>x</p>'))->toBeTrue()
        ->and(HtmlSanitizer::tooComplex('<svg '.$names($limit + 1, ' ').'></svg>'))->toBeTrue();
});

it('counts the attributes of one tag, not of the whole text, and leaves a long value and a living markup alone', function (): void {
    $limit = DepthLimitedParser::MAX_ATTRIBUTES;
    $tags = str_repeat('<img src="a.png" alt="Фото" width="10" height=20 data-x=\'y\' hidden>', 1000);
    // Значение в кавычках с «>» и пробелами — один атрибут, даже если оно огромно; закрывающий тег с именами не считается.
    $long = '<a title="'.str_repeat('a b > c ', 5000).'" href="/x">link</a></p '.str_repeat('a ', $limit + 1).'>';

    expect(HtmlSanitizer::tooComplex($tags))->toBeFalse()
        ->and(HtmlSanitizer::tooComplex($long))->toBeFalse()
        ->and(HtmlSanitizer::tooComplex('1 < 2 and 3 > 2 <3'))->toBeFalse()
        ->and(HtmlSanitizer::clean('<p '.implode(' ', array_map(fn (int $number): string => 'a'.$number, range(1, $limit + 1))).'>x</p>'))->toBeNull();
});
