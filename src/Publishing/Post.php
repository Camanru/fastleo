<?php

declare(strict_types=1);

namespace Fastleo\Publishing;

/**
 * Черновик поста из записи сайта — его собирает замыкание действия `Publish`, а админ правит перед отправкой.
 *
 * ```php
 * Post::make("**{$news->title}**\n\n{$news->lead}")
 *     ->images([$news->photo])                       // пути на диске файлов админки (или ->images($paths, 's3'))
 *     ->button('Читать на сайте', route('news.show', $news));
 * ```
 */
final class Post
{
    /** @var list<string> */
    private array $images = [];

    private ?string $disk = null;

    private ?string $buttonLabel = null;

    private ?string $buttonUrl = null;

    private function __construct(private readonly string $markdown) {}

    /**
     * Текст поста в Markdown.
     */
    public static function make(string $markdown): self
    {
        return new self($markdown);
    }

    /**
     * Картинки: пути на диске (по умолчанию — диск файлов админки `fastleo.files.disk`). Пустые значения
     * пропускаются, в публикацию копируются первые 10.
     *
     * @param  array<array-key, string|null>  $paths
     */
    public function images(array $paths, ?string $disk = null): self
    {
        $this->images = array_values(array_filter($paths, fn (?string $path): bool => is_string($path) && $path !== ''));
        $this->disk = $disk;

        return $this;
    }

    /**
     * Кнопка-ссылка под постом.
     */
    public function button(string $label, string $url): self
    {
        $this->buttonLabel = $label;
        $this->buttonUrl = $url;

        return $this;
    }

    /**
     * Текст в Markdown.
     */
    public function markdown(): string
    {
        return $this->markdown;
    }

    /**
     * Пути картинок.
     *
     * @return list<string>
     */
    public function imagePaths(): array
    {
        return $this->images;
    }

    /**
     * Диск картинок или null — диск файлов админки.
     */
    public function disk(): ?string
    {
        return $this->disk;
    }

    /**
     * Текст кнопки.
     */
    public function buttonLabel(): ?string
    {
        return $this->buttonLabel;
    }

    /**
     * Адрес кнопки.
     */
    public function buttonUrl(): ?string
    {
        return $this->buttonUrl;
    }
}
