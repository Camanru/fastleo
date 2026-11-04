{{-- Video: ввод ссылки (как у Url) и под ним плеер видео. --}}
@include('fastleo::fields.text')
@if (\Fastleo\Support\Html\VideoUrl::embed(fastleo_old($path, $value)) !== null)
    <x-fastleo::video :url="fastleo_old($path, $value)" class="max-w-lg" />
@endif
