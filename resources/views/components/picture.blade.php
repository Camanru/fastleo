@props(['src' => null, 'preset' => null, 'alt' => '', 'sizes' => '100vw'])
{{--
    Картинка для сайта с WebP-вариантами из fastleo.images.presets:
    <x-fastleo::picture :src="$doctor->photo" preset="card" alt="Иванов И. И." sizes="(min-width: 768px) 400px, 100vw" class="rounded-lg" />
    Варианты ещё не созданы — выводится оригинал. Атрибуты (class, loading, fetchpriority) уходят в <img>.
--}}
@php
    $path = is_string($src) && $src !== '' ? $src : null;
    $variants = $path !== null && is_string($preset) ? \Fastleo\Files\Variants::sizes($path, $preset) : [];
    $largest = array_key_last($variants);
@endphp
@if ($path !== null)
    @if ($variants === [])
        <img src="{{ \Fastleo\Files\FileStore::url($path) }}" alt="{{ $alt }}" {{ $attributes->merge(['loading' => 'lazy', 'decoding' => 'async']) }}>
    @else
        <picture>
            <source type="image/webp" srcset="{{ implode(', ', array_map(fn (int $width): string => \Fastleo\Files\Variants::url($path, $preset, $width).' '.$width.'w', array_keys($variants))) }}" sizes="{{ $sizes }}">
            <img src="{{ \Fastleo\Files\FileStore::url($path) }}" alt="{{ $alt }}" width="{{ $largest }}" height="{{ $variants[$largest] }}" {{ $attributes->merge(['loading' => 'lazy', 'decoding' => 'async']) }}>
        </picture>
    @endif
@endif
