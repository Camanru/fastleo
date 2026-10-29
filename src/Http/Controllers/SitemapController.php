<?php

declare(strict_types=1);

namespace Fastleo\Http\Controllers;

use Fastleo\Support\Sitemap;
use Illuminate\Http\Response;

/**
 * Публичная карта сайта `/sitemap.xml` и её файлы `/sitemap-N.xml` (без входа). Маршрут есть, только если `fastleo.sitemap.enabled` = true.
 */
final class SitemapController
{
    /**
     * XML карты сайта.
     */
    public function __invoke(): Response
    {
        return $this->xml(Sitemap::xml());
    }

    /**
     * Файл карты `/sitemap-{page}.xml`: есть, только когда `/sitemap.xml` — индекс.
     */
    public function page(int $page): Response
    {
        return $this->xml(Sitemap::page($page) ?? abort(404));
    }

    private function xml(string $xml): Response
    {
        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
