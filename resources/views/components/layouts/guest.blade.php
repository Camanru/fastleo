@props(['title' => null])
{{-- Страницы без входа: форма входа по центру экрана на фоне из точек с пятном цвета акцента. --}}
<!DOCTYPE html>
<html lang="ru" class="h-full" data-theme="{{ \Fastleo\Support\Branding::theme() }}">
<head>
    @include('fastleo::partials.head', ['title' => $title])
</head>
<body class="relative flex min-h-full items-center justify-center overflow-hidden bg-bg p-4 text-ink antialiased">
    @include('fastleo::partials.assets-warning')
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,var(--color-line-strong)_1px,transparent_0)] [mask-image:radial-gradient(ellipse_70%_60%_at_50%_40%,#000_10%,transparent_75%)] bg-[size:22px_22px]" aria-hidden="true"></div>
    <div class="pointer-events-none absolute top-[-260px] left-1/2 h-[520px] w-[820px] max-w-full -translate-x-1/2 bg-[radial-gradient(closest-side,var(--color-primary-soft),transparent)]" aria-hidden="true"></div>
    <main class="relative flex w-full max-w-[392px] flex-col items-center gap-5">
        <div class="text-lg font-[650]">
            @include('fastleo::partials.brand')
        </div>
        <div class="w-full">
            {{ $slot }}
        </div>
        <p class="text-xs text-ink-3">{{ __('fastleo::auth.powered_by') }}</p>
    </main>
</body>
</html>
