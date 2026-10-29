@props(['action', 'method' => 'POST', 'confirm' => null, 'button' => null, 'danger' => true])
{{--
    Форма одного действия (удалить, восстановить) с CSRF и, при необходимости, своим окном подтверждения:
    <x-fastleo::action-form :action="..." method="DELETE" :confirm="__('...')">кнопка</x-fastleo::action-form>
    Для не опасного действия: :danger="false" button="Вернуть" (кнопка согласия основного цвета со своей подписью).
--}}
<form
    method="POST"
    action="{{ $action }}"
    @if ($confirm) x-data="fastleoConfirm" data-confirm="{{ $confirm }}" @if ($button) data-confirm-button="{{ $button }}" @endif @unless ($danger) data-confirm-danger="0" @endunless @submit.prevent="ask" @endif
    {{ $attributes->class('inline') }}
>
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif
    {{ $slot }}
</form>
