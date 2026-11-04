{{-- Общий <head> админки: тема и свёрнутость меню до загрузки стилей (без мигания), стили пакета и сайта, основной цвет. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ? $title.' — ' : '' }}{{ \Fastleo\Support\Branding::name() }}</title>
<script>
    (function () {
        // Закрытое хранилище браузера не должно ронять страницу: тогда действуют значения по умолчанию.
        function stored(key) {
            try {
                return localStorage.getItem(key);
            } catch (error) {
                return null;
            }
        }

        var mode = stored('fastleo-theme');
        if (['light', 'dark', 'system'].indexOf(mode) === -1) {
            mode = document.documentElement.dataset.theme;
        }
        var dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);

        var sidebar = stored('fastleo-sidebar');
        document.documentElement.classList.toggle('fl-compact', stored('fastleo-density') === 'compact');
        document.documentElement.classList.toggle('fl-collapsed', sidebar === null ? {!! match (config('fastleo.menu.collapsed')) { true => 'true', false => 'false', default => 'window.innerWidth < 1280' } !!} : sidebar === '1');
    })();
</script>
<link rel="stylesheet" href="{{ \Fastleo\Support\Assets::url('fastleo.css') }}">
@foreach (\Fastleo\Support\Branding::stylesheets() as $stylesheet)
    <link rel="stylesheet" href="{{ $stylesheet }}">
@endforeach
<style>:root { --fastleo-primary: {{ \Fastleo\Support\Branding::color() }}; }</style>
<script defer src="{{ \Fastleo\Support\Assets::url('fastleo.js') }}"></script>
