@props(['title' => null])
{{-- Страница внутри окна выбора (iframe): файловый менеджер без меню и шапки. --}}
<!DOCTYPE html>
<html lang="ru" class="h-full" data-theme="{{ \Fastleo\Support\Branding::theme() }}">
<head>
    @include('fastleo::partials.head', ['title' => $title])
</head>
<body class="min-h-full bg-bg p-3 text-ink antialiased sm:p-4">
    @include('fastleo::partials.assets-warning')
    {{ $slot }}

    @include('fastleo::partials.confirm-dialog')
    @stack('fastleo-scripts')
</body>
</html>
