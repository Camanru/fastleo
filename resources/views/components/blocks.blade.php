@props(['blocks' => [], 'prefix' => 'blocks'])
{{--
    Вывод блоков поля Blocks на сайте: каждый блок — шаблон сайта «{prefix}.{тип}»
    (по умолчанию resources/views/blocks/text.blade.php). Шаблон получает поля блока переменными
    ($body, $src…), а также $block (все поля массивом) и $type. Блок без шаблона пропускается.

    <x-fastleo::blocks :blocks="$page->content" />
    <x-fastleo::blocks :blocks="$page->content" prefix="landing.blocks" />
--}}
@php
    $items = is_string($blocks) ? json_decode($blocks, true) : $blocks;
@endphp
@foreach (is_iterable($items) ? $items : [] as $item)
    @php
        $type = is_array($item) && is_string($item['type'] ?? null) && preg_match('/^[a-z][a-z0-9_]*$/', $item['type']) === 1 ? $item['type'] : null;
        $data = is_array($item) && is_array($item['data'] ?? null) ? $item['data'] : [];
        $view = $type === null ? null : $prefix.'.'.$type;
    @endphp
    @if ($view !== null && view()->exists($view))
        @include($view, [...array_filter($data, fn ($key) => is_string($key) && preg_match('/^[A-Za-z_]\w*$/', $key) === 1, ARRAY_FILTER_USE_KEY), 'block' => $data, 'type' => $type])
    @endif
@endforeach
