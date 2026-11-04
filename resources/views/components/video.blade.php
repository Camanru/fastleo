@props(['url' => null, 'title' => ''])
{{--
    Плеер видео по ссылке на YouTube, Rutube или VK Видео (поле Video). Ссылка не на видео или пустая — ничего не выводится.
    Размер задаёт ширина блока (16:9); стили встроены, чтобы не зависеть от CSS сайта. Свои атрибуты (class) попадают на обёртку.

    <x-fastleo::video :url="$doctor->video" title="Приём у врача" />
--}}
@php
    $embed = \Fastleo\Support\Html\VideoUrl::embed($url);
@endphp
@if ($embed !== null)
    <div {{ $attributes->merge(['style' => 'position:relative;width:100%;aspect-ratio:16/9;overflow:hidden;background:#000']) }}>
        <iframe src="{{ $embed }}" title="{{ $title }}" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="fullscreen; picture-in-picture; encrypted-media" style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe>
    </div>
@endif
