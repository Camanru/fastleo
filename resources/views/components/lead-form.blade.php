@props(['withEmail' => false, 'withMessage' => true, 'button' => null, 'sent' => null, 'nameLabel' => null, 'phoneLabel' => null, 'emailLabel' => null, 'messageLabel' => null])
{{--
    Форма заявки для сайта (fastleo.leads.resource и fastleo.leads.form): имя, телефон, по желанию почта и сообщение,
    скрытая метка времени и поле-ловушка для ботов, ошибки и «Спасибо!». Разметка без оформления — стилизуйте своим CSS.
    Подписи по умолчанию русские; свои — атрибутами. Своя разметка — тот же набор полей, `@csrf` и FormToken::make().

    <x-fastleo::lead-form />
    <x-fastleo::lead-form :with-email="true" button="Заказать звонок" />
--}}
@php
    $locale = \Fastleo\Fastleo::LOCALE;
@endphp
<form method="post" action="{{ route('fastleo-leads.store') }}" {{ $attributes }}>
    @csrf
    <input type="hidden" name="form_token" value="{{ \Fastleo\Leads\FormToken::make() }}">
    <div style="position: absolute; left: -9999px;" aria-hidden="true">
        <input type="text" name="leave_empty" tabindex="-1" autocomplete="off">
    </div>

    <label>
        {{ $nameLabel ?? __('fastleo::leads.name', [], $locale) }}
        <input type="text" name="name" value="{{ old('name') }}" maxlength="100" required>
    </label>
    @error('name') <p role="alert">{{ $message }}</p> @enderror

    <label>
        {{ $phoneLabel ?? __('fastleo::leads.phone', [], $locale) }}
        <input type="tel" name="phone" value="{{ old('phone') }}" maxlength="50" required>
    </label>
    @error('phone') <p role="alert">{{ $message }}</p> @enderror

    @if ($withEmail)
        <label>
            {{ $emailLabel ?? __('fastleo::leads.email', [], $locale) }}
            <input type="email" name="email" value="{{ old('email') }}" maxlength="255">
        </label>
        @error('email') <p role="alert">{{ $message }}</p> @enderror
    @endif

    @if ($withMessage)
        <label>
            {{ $messageLabel ?? __('fastleo::leads.message', [], $locale) }}
            <textarea name="message" maxlength="2000">{{ old('message') }}</textarea>
        </label>
        @error('message') <p role="alert">{{ $message }}</p> @enderror
    @endif

    @error('form_token') <p role="alert">{{ $message }}</p> @enderror

    <button type="submit">{{ $button ?? __('fastleo::leads.form_submit', [], $locale) }}</button>

    @if (session('lead_sent'))
        <p role="status">{{ $sent ?? __('fastleo::leads.form_sent', [], $locale) }}</p>
    @endif
</form>
