<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Article;
use Workbench\App\Models\Page;

// Демо-страница сайта: блоки поля Blocks выводит <x-fastleo::blocks>.
Route::get('/site-pages/{slug}', fn (string $slug): Factory|\Illuminate\Contracts\View\View => view('page', ['page' => Page::query()->where('slug', $slug)->firstOrFail()]))
    ->name('site-pages.show');

// Демо-страница сайта: контакты берутся из настроек админки.
Route::view('/contacts', 'contacts')->name('contacts');

// Демо-страница статьи: мета-теги из поля Seo выводит <x-fastleo::seo>.
Route::get('/articles/{article}', fn (Article $article): Factory|\Illuminate\Contracts\View\View => view('article', ['article' => $article]))
    ->name('articles.show');
