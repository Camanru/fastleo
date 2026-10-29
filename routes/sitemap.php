<?php

declare(strict_types=1);

use Fastleo\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
 * Публичная карта сайта. Подключается провайдером, только если fastleo.sitemap.enabled = true, —
 * иначе маршрут занял бы адрес /sitemap.xml, который сайт мог описать сам.
 */
Route::get('sitemap.xml', SitemapController::class)->name('fastleo.sitemap');
Route::get('sitemap-{page}.xml', [SitemapController::class, 'page'])->where('page', '[1-9][0-9]{0,2}')->name('fastleo.sitemap.page');
