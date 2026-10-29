{{-- Демо-страница статьи: мета-теги из поля Seo, заголовок и анонс. --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-fastleo::seo :model="$article" :title="$article->title" :description="$article->content" :image="$article->cover" type="article" />
    <style>body { font-family: system-ui, sans-serif; max-width: 48rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.6 } img { max-width: 100% }</style>
</head>
<body>
    <h1>{{ $article->title }}</h1>
    {!! $article->content !!}
</body>
</html>
