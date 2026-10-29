{{-- Страница для посетителей закрытого сайта. Без ассетов админки: стили встроены. Сайт может заменить файл своим:
     resources/views/vendor/fastleo/site-closed.blade.php (в шаблон приходит $message). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('fastleo::closing.title', [], \Fastleo\Fastleo::LOCALE) }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; box-sizing: border-box; background: #f8fafc; color: #0f172a; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 480px; text-align: center; }
        h1 { margin: 0 0 12px; font-size: 24px; font-weight: 650; }
        p { margin: 0; font-size: 16px; line-height: 1.5; color: #475569; white-space: pre-line; }
        @media (prefers-color-scheme: dark) { body { background: #0f172a; color: #f1f5f9; } p { color: #94a3b8; } }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('fastleo::closing.title', [], \Fastleo\Fastleo::LOCALE) }}</h1>
        <p>{{ $message }}</p>
    </main>
</body>
</html>
