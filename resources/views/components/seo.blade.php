@props(['model' => null, 'field' => 'seo', 'title' => null, 'description' => null, 'image' => null, 'canonical' => null, 'type' => 'website'])
{{--
    Мета-теги страницы для <head>: title, description, canonical, robots, Open Graph и Twitter.
    Значения берутся из поля Seo записи, затем из переданных атрибутов, затем из названия сайта (APP_NAME).
    Описание можно передать HTML-текстом записи — оно очистится от тегов и обрежется до 160 знаков.

    <x-fastleo::seo :model="$article" :title="$article->title" :description="$article->body" :image="$article->cover" type="article" />
--}}
@php
    $tags = \Fastleo\Support\SeoTags::resolve($model, $field, $title, $description, $image, $canonical, $type);
@endphp
@if ($tags['title'] !== null)
    <title>{!! e($tags['title'], false) !!}</title>
@endif
@if ($tags['description'] !== null)
    <meta name="description" content="{{ $tags['description'] }}">
@endif
<link rel="canonical" href="{{ $tags['canonical'] }}">
@if ($tags['noindex'])
    <meta name="robots" content="noindex">
@endif
<meta property="og:type" content="{{ $tags['type'] }}">
<meta property="og:url" content="{{ $tags['canonical'] }}">
@if ($tags['site'] !== '')
    <meta property="og:site_name" content="{{ $tags['site'] }}">
@endif
@if ($tags['title'] !== null)
    <meta property="og:title" content="{!! e($tags['title'], false) !!}">
@endif
@if ($tags['description'] !== null)
    <meta property="og:description" content="{{ $tags['description'] }}">
@endif
@if ($tags['image'] !== null)
    <meta property="og:image" content="{{ $tags['image'] }}">
@endif
<meta name="twitter:card" content="{{ $tags['image'] !== null ? 'summary_large_image' : 'summary' }}">
