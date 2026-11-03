@props(['title' => null, 'picker' => false, 'closable' => false])
{{-- Страница внутри окна (iframe): файловый менеджер или форма записи без меню и шапки. picker — окно выбора файла
     для полей формы; closable — Escape просит окно-родителя закрыться (если в странице нет другого открытого окна). --}}
<!DOCTYPE html>
<html lang="ru" class="h-full" data-theme="{{ \Fastleo\Support\Branding::theme() }}">
<head>
    @include('fastleo::partials.head', ['title' => $title])
</head>
<body class="min-h-full bg-bg p-4 text-ink antialiased" @if ($closable) x-data @keydown.escape.window="if (! document.querySelector('dialog[open]')) window.parent.postMessage({ type: 'fastleo:close' }, window.location.origin)" @endif>
    @include('fastleo::partials.assets-warning')
    {{ $slot }}

    @if ($picker)
        @include('fastleo::partials.file-picker')
    @endif

    @include('fastleo::partials.confirm-dialog')
    @stack('fastleo-scripts')
</body>
</html>
