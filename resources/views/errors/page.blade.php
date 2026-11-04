{{-- Страница ошибки админки (403, 404, 419, 429): в меню админки, на экране входа или в окне. Данные: status, icon, title, text, retry, signedIn, framed. --}}
@php
    $home = route('fastleo.dashboard');
    // В меню админки заголовок страницы уже выводит макет, поэтому внутри повторять его не нужно.
    $showTitle = $framed || ! $signedIn;
@endphp
@if ($framed)
    <x-fastleo::layouts.frame :title="$title">
        @include('fastleo::errors.content')
    </x-fastleo::layouts.frame>
@elseif ($signedIn)
    <x-fastleo::layouts.app :title="$title">
        @include('fastleo::errors.content')
    </x-fastleo::layouts.app>
@else
    <x-fastleo::layouts.guest :title="$title">
        @include('fastleo::errors.content')
    </x-fastleo::layouts.guest>
@endif
