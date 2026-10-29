@props(['name'])
{{-- Иконка Lucide: <x-fastleo::icon name="house" class="size-5" /> --}}
{{ \Fastleo\Support\Icons::render($name, $attributes) }}
