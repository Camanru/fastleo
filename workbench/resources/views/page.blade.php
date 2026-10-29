{{-- Демо-страница сайта: заголовок и блоки конструктора. --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->title['ru'] ?? '' }}</title>
    <style>body { font-family: system-ui, sans-serif; max-width: 48rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.6 } img { max-width: 100% } blockquote { border-left: 4px solid #6366f1; margin: 1.5rem 0; padding-left: 1rem }</style>
</head>
<body>
    <h1>{{ $page->title['ru'] ?? '' }}</h1>
    <x-fastleo::blocks :blocks="$page->content" />
</body>
</html>
