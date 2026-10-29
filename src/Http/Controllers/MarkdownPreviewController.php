<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Fields\Markdown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Просмотр поля Markdown: текст из формы → безопасный HTML.
 */
final class MarkdownPreviewController
{
    /** Наибольшая длина текста для просмотра. */
    private const int MAX_LENGTH = 200_000;

    /**
     * `POST /fastleo/markdown` с полем `text` → `{"html": "..."}`.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $text = $request->input('text');
        $text = is_string($text) ? mb_substr($text, 0, self::MAX_LENGTH) : '';

        return response()->json(['html' => Markdown::render($text)->toHtml()]);
    }
}
