<?php

declare(strict_types=1);

use Fastleo\Messengers\TelegramBot;
use Fastleo\Publishing\MessageHtml;

it('keeps only the tags messengers understand and escapes the rest', function (string $markdown, string $html): void {
    expect(MessageHtml::fromMarkdown($markdown))->toBe($html);
})->with([
    'bold, italic, strike, code' => ['**a** *b* ~~c~~ `d <e>`', '<b>a</b> <i>b</i> <s>c</s> <code>d &lt;e&gt;</code>'],
    'raw html is text' => ['<script>alert(1)</script> & <b>x</b>', '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;x&lt;/b&gt;'],
    'http link' => ['[сайт](https://site.ru/a?x=1&y=2)', '<a href="https://site.ru/a?x=1&amp;y=2">сайт</a>'],
    'unsafe link is text' => ['[жми](javascript:alert(1)) [почта](mailto:a@b.ru)', 'жми почта'],
    'paragraphs and headings' => ["# Заголовок\n\nабзац\nстрока", "<b>Заголовок</b>\n\nабзац\nстрока"],
    'lists' => ["- раз\n- два\n\n1. один\n2. два", "• раз\n• два\n\n1. один\n2. два"],
    'nested quote is flattened' => ["> раз\n> > два", "<blockquote>раз\n\nдва</blockquote>"],
    'code block' => ["```\n<b>\n```", '<pre>&lt;b&gt;</pre>'],
    'image becomes its caption' => ['![кот](https://x.ru/cat.png)', 'кот'],
    'table becomes text' => ["| a | b |\n|---|---|\n| 1 | 2 |", "a | b\n1 | 2"],
]);

it('counts the visible text the way Telegram does', function (): void {
    expect(TelegramBot::visibleLength('<b>ab</b> &amp; 😀'))->toBe(7);
});
