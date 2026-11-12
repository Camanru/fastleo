@props(['withEmail' => false, 'withMessage' => true, 'button' => null, 'sent' => null, 'nameLabel' => null, 'phoneLabel' => null, 'emailLabel' => null, 'messageLabel' => null, 'anchor' => null, 'formName' => null])
{{--
    Форма заявки для сайта (fastleo.leads.resource и fastleo.leads.form): имя, телефон, по желанию почта и сообщение,
    скрытая метка времени и поле-ловушка для ботов, ошибки и «Спасибо!». Разметка без оформления — стилизуйте своим CSS.
    Подписи по умолчанию русские; свои — атрибутами. Своя разметка — тот же набор полей, `@csrf` и FormToken::make().

    <x-fastleo::lead-form />
    <x-fastleo::lead-form :with-email="true" button="Заказать звонок" />

    Несколько форм на странице: у каждой свой `anchor` (латиница, цифры, дефис). После отправки посетитель возвращается к этой форме
    (`#form-{anchor}`), «Спасибо» и ошибки видны только у неё. `form-name` — название формы или тема, оно попадёт в поле «Форма» заявки.

    <x-fastleo::lead-form anchor="hero" form-name="Первый экран" />
--}}
@php
    $locale = \Fastleo\Fastleo::LOCALE;
    $anchor = \Fastleo\Leads\Leads::anchor($anchor);
    $bag = $anchor === null ? 'default' : 'lead_'.$anchor;
    // Введённое при ошибке подставляется только в ту форму, которую отправили.
    $own = old('form_anchor') === $anchor;
@endphp
<form method="post" action="{{ route('fastleo-leads.store') }}" {{ $attributes->merge($anchor === null ? [] : ['id' => 'form-'.$anchor]) }}>
    @csrf
    @if ($anchor !== null)
        <input type="hidden" name="form_anchor" value="{{ $anchor }}">
    @endif
    @if (($formLabel = \Fastleo\Leads\Leads::formName($formName)) !== null)
        <input type="hidden" name="form" value="{{ $formLabel }}">
    @endif
    <input type="hidden" name="form_token" value="{{ \Fastleo\Leads\FormToken::make() }}">
    <div style="position: absolute; left: -9999px;" aria-hidden="true">
        <input type="text" name="leave_empty" tabindex="-1" autocomplete="off">
    </div>

    <label>
        {{ $nameLabel ?? __('fastleo::leads.name', [], $locale) }}
        <input type="text" name="name" value="{{ $own ? old('name') : '' }}" maxlength="100" required>
    </label>
    @error('name', $bag) <p role="alert">{{ $message }}</p> @enderror

    <label>
        {{ $phoneLabel ?? __('fastleo::leads.phone', [], $locale) }}
        <input type="tel" name="phone" value="{{ $own ? old('phone') : '' }}" maxlength="50" required>
    </label>
    @error('phone', $bag) <p role="alert">{{ $message }}</p> @enderror

    @if ($withEmail)
        <label>
            {{ $emailLabel ?? __('fastleo::leads.email', [], $locale) }}
            <input type="email" name="email" value="{{ $own ? old('email') : '' }}" maxlength="255">
        </label>
        @error('email', $bag) <p role="alert">{{ $message }}</p> @enderror
    @endif

    @if ($withMessage)
        <label>
            {{ $messageLabel ?? __('fastleo::leads.message', [], $locale) }}
            <textarea name="message" maxlength="2000">{{ $own ? old('message') : '' }}</textarea>
        </label>
        @error('message', $bag) <p role="alert">{{ $message }}</p> @enderror
    @endif

    @error('form', $bag) <p role="alert">{{ $message }}</p> @enderror
    @error('form_token', $bag) <p role="alert">{{ $message }}</p> @enderror

    <button type="submit">{{ $button ?? __('fastleo::leads.form_submit', [], $locale) }}</button>

    @if (session('lead_sent') && session('lead_sent_anchor') === $anchor)
        <p role="status">{{ $sent ?? __('fastleo::leads.form_sent', [], $locale) }}</p>
    @endif
</form>
