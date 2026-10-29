<?php

declare(strict_types=1);

namespace Fastleo\Support;

use XMLWriter;

/**
 * Раскладывает адреса карты сайта по файлам: в каждом не больше `$perFile` адресов, файлов не больше `$maxFiles`.
 * Повторяющийся адрес пропускается. Всегда есть хотя бы один файл (пустая, но верная карта).
 */
final class SitemapFiles
{
    /** @var list<string> */
    private array $files = [];

    private XMLWriter $writer;

    private int $inFile = 0;

    private bool $full = false;

    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(private readonly int $perFile, private readonly int $maxFiles)
    {
        $this->writer = $this->open();
    }

    /**
     * Все файлы заполнены: новые адреса уже не принимаются.
     */
    public function isFull(): bool
    {
        return $this->full;
    }

    /**
     * Добавить адрес (и дату изменения, если она есть).
     */
    public function add(string $loc, ?string $lastmod): void
    {
        if ($this->full || isset($this->seen[$loc])) {
            return;
        }

        if ($this->inFile >= $this->perFile) {
            $this->close();

            if (count($this->files) >= $this->maxFiles) {
                $this->full = true;

                return;
            }

            $this->writer = $this->open();
        }

        $this->seen[$loc] = true;
        $this->inFile++;
        $this->writer->startElement('url');
        $this->writer->writeElement('loc', $loc);

        if ($lastmod !== null) {
            $this->writer->writeElement('lastmod', $lastmod);
        }

        $this->writer->endElement();
    }

    /**
     * Закончить последний файл и отдать все.
     *
     * @return list<string>
     */
    public function finish(): array
    {
        if (! $this->full) {
            $this->close();
        }

        return $this->files;
    }

    private function open(): XMLWriter
    {
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $this->inFile = 0;

        return $writer;
    }

    private function close(): void
    {
        $this->writer->endElement();
        $this->writer->endDocument();
        $this->files[] = $this->writer->outputMemory();
    }
}
