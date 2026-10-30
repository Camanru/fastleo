<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Closure;
use Fastleo\Support\Html\RichTextSettings;
use Fastleo\Support\Html\SafeStyleSanitizer;
use Fastleo\Support\Html\TextAlignSanitizer;
use Fastleo\Support\Html\VideoFrameSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Чистка HTML из редактора (поле RichText) — symfony/html-sanitizer.
 *
 * Остаётся структура текста: абзацы, заголовки h2–h4, списки, жирный и курсив, ссылки
 * (http, https, mailto, tel, относительные), картинки (http, https, относительные — без `data:`),
 * таблицы, цитаты, код, видео YouTube / Rutube / VK. Из `style` — только `text-align` (или безопасный `style` целиком — настройка `fastleo.rich_text.styles`).
 * Дополнительные теги, атрибуты и плееры сайт задаёт в `fastleo.rich_text`.
 * Скрипты, обработчики `on*`, `javascript:`, формы, `svg`, `style` удаляются вместе
 * с содержимым; прочие теги (`div`, `span`, `h1`) снимаются, текст внутри остаётся.
 *
 * ```php
 * HtmlSanitizer::clean('<p onclick="x()">Текст<script>alert(1)</script></p>'); // '<p>Текст</p>'
 * ```
 */
final class HtmlSanitizer
{
    /** Наибольшая длина HTML в символах (проверка поля RichText); санитайзер обрезает по байтам — с запасом на 4 байта UTF-8. */
    public const int MAX_LENGTH = 1_000_000;

    /** Теги, которые удаляются вместе с содержимым. */
    public const array DROPPED = [
        'script', 'style', 'noscript', 'template', 'object', 'embed', 'applet', 'form', 'input', 'button',
        'textarea', 'select', 'option', 'frame', 'frameset', 'svg', 'math', 'head', 'title', 'meta', 'link', 'base',
    ];

    /**
     * Профиль чистки по умолчанию.
     */
    public static function config(): HtmlSanitizerConfig
    {
        $aligned = ['style'];
        $config = (new HtmlSanitizerConfig)
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            ->withAttributeSanitizer(RichTextSettings::styles() ? new SafeStyleSanitizer : new TextAlignSanitizer)
            ->withAttributeSanitizer(new VideoFrameSanitizer)
            ->forceAttribute('a', 'rel', 'noopener')
            ->withMaxInputLength(self::MAX_LENGTH * 4);

        foreach (['p', 'h2', 'h3', 'h4', 'blockquote', 'li'] as $element) {
            $config = $config->allowElement($element, $aligned);
        }

        foreach (['br', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'hr', 'pre', 'code', 'ul', 'figure', 'figcaption', 'thead', 'tbody', 'tfoot', 'tr', 'caption'] as $element) {
            $config = $config->allowElement($element);
        }

        $config = $config
            ->allowElement('ol', ['start'])
            ->allowElement('a', ['href', 'title', 'target'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'loading'])
            ->allowElement('table', [])
            ->allowElement('th', ['colspan', 'rowspan', 'scope', 'style'])
            ->allowElement('td', ['colspan', 'rowspan', 'style'])
            ->allowElement('iframe', ['src', 'width', 'height', 'title', 'allowfullscreen', 'loading', 'referrerpolicy', 'frameborder']);

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        RichTextSettings::iframeHosts();

        foreach (RichTextSettings::tags() as $element) {
            if (! array_key_exists($element, $config->getAllowedElements())) {
                $config = $config->allowElement($element);
            }
        }

        $extra = RichTextSettings::attributes();

        if (RichTextSettings::styles()) {
            $extra[] = 'style';
        }

        foreach ($extra as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }

        return $config;
    }

    /**
     * Очищенный HTML; пустой текст (`<p></p>`, `<p><br></p>`) — null. Тег без текста, который разрешил сайт (`rich_text.tags`), или картинка, видео, таблица пустым не считается.
     *
     * ```php
     * HtmlSanitizer::clean($html, fn (HtmlSanitizerConfig $config) => $config->allowElement('span', ['class']));
     * ```
     *
     * @param  (Closure(HtmlSanitizerConfig): HtmlSanitizerConfig)|null  $customize  дополнить профиль
     */
    public static function clean(?string $html, ?Closure $customize = null): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $config = $customize instanceof Closure ? $customize(self::config()) : self::config();
        $clean = trim(new SymfonySanitizer($config)->sanitize($html));

        // Теги без текста, но со смыслом: картинки, видео, таблицы и теги, которые разрешил сайт (`<div data-lpu="1"></div>` — виджет).
        $meaningful = implode('|', array_unique(['img', 'iframe', 'hr', 'table', ...RichTextSettings::tags()]));
        $hasContent = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{A0}") !== ''
            || preg_match('/<('.$meaningful.')\b/i', $clean) === 1;

        return $hasContent ? $clean : null;
    }
}
