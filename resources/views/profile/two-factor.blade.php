{{-- Двухфакторная защита в профиле. Нужны $input, $card и данные профиля: $twoFactorEnabled, $twoFactorSecret, $twoFactorQr, $recoveryLeft. --}}
@php($recoveryCodes = session('fastleo.recovery_codes'))
<section class="{{ $card }}" data-profile-two-factor>
    <div>
        <h2 class="flex items-center gap-2 text-md font-semibold tracking-[-0.01em]">
            {{ __('fastleo::profile.two_factor_heading') }}
            <x-fastleo::badge :tone="$twoFactorEnabled ? 'success' : 'warning'" data-two-factor-state>
                {{ $twoFactorEnabled ? __('fastleo::profile.two_factor_on') : __('fastleo::profile.two_factor_off') }}
            </x-fastleo::badge>
        </h2>
        <p class="text-sm text-ink-2">{{ __('fastleo::profile.two_factor_help') }}</p>
    </div>

    @if (is_array($recoveryCodes))
        <div class="rounded-md border border-warning/40 bg-warning-soft p-4 text-sm" data-recovery-codes>
            <h3 class="font-semibold">{{ __('fastleo::profile.recovery_heading') }}</h3>
            <p class="mt-1 text-ink">{{ __('fastleo::profile.recovery_help') }}</p>
            <ul class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 rounded-[10px] bg-sunken px-3.5 py-3 font-mono text-sm tabular-nums">
                @foreach ($recoveryCodes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($twoFactorEnabled)
        <p class="text-sm text-ink-2">{{ __('fastleo::profile.recovery_left', ['count' => $recoveryLeft]) }}</p>

        <form method="POST" action="{{ route('fastleo.profile.two-factor.recovery-codes') }}" class="flex flex-wrap items-end gap-3" data-two-factor-recovery>
            @csrf
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                {{ __('fastleo::profile.current_password') }}
                <input type="password" name="current_password" required maxlength="255" autocomplete="current-password" class="{{ $input }}">
            </label>
            <x-fastleo::button type="submit">{{ __('fastleo::profile.recovery_new') }}</x-fastleo::button>
        </form>

        <form method="POST" action="{{ route('fastleo.profile.two-factor.disable') }}" class="flex flex-wrap items-end gap-3" data-two-factor-disable>
            @csrf
            @method('DELETE')
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                {{ __('fastleo::profile.current_password') }}
                <input type="password" name="current_password" required maxlength="255" autocomplete="current-password" class="{{ $input }}">
            </label>
            <x-fastleo::button type="submit" variant="danger">{{ __('fastleo::profile.two_factor_disable') }}</x-fastleo::button>
        </form>
    @elseif ($twoFactorSecret !== null)
        <div class="flex flex-col gap-4" data-two-factor-setup>
            <div class="flex flex-wrap items-start gap-4">
                <div class="shrink-0 rounded-card border border-line bg-white p-2.5" aria-hidden="true">{!! $twoFactorQr !!}</div>
                <ol class="flex min-w-0 flex-1 list-decimal flex-col gap-2 pl-4 text-sm text-ink-2 marker:text-ink-3">
                    <li>{{ __('fastleo::profile.two_factor_step_app') }}</li>
                    <li>
                        {{ __('fastleo::profile.two_factor_step_scan') }}
                        <span class="mt-1 flex flex-wrap items-center gap-2" x-data>
                            <code class="font-mono text-xs tracking-wide text-ink" data-two-factor-secret>{{ trim(chunk_split($twoFactorSecret, 4, ' ')) }}</code>
                            <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-primary-text hover:underline" x-on:click="navigator.clipboard?.writeText(@js($twoFactorSecret))">
                                <x-fastleo::icon name="copy" class="size-3.5" />{{ __('fastleo::profile.two_factor_copy') }}
                            </button>
                        </span>
                    </li>
                    <li>{{ __('fastleo::profile.two_factor_step_code') }}</li>
                </ol>
            </div>
            <form method="POST" action="{{ route('fastleo.profile.two-factor.confirm') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                    {{ __('fastleo::profile.current_password') }}
                    <input type="password" name="current_password" required maxlength="255" autocomplete="current-password" class="{{ $input }}">
                </label>
                <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                    {{ __('fastleo::profile.two_factor_code') }}
                    <input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" class="{{ $input }} w-[140px] font-mono tracking-[.12em]">
                </label>
                <x-fastleo::button type="submit" variant="primary">{{ __('fastleo::profile.two_factor_confirm') }}</x-fastleo::button>
            </form>
        </div>
    @else
        <form method="POST" action="{{ route('fastleo.profile.two-factor.enable') }}" class="flex flex-wrap items-end gap-3" data-two-factor-enable>
            @csrf
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                {{ __('fastleo::profile.current_password') }}
                <input type="password" name="current_password" required maxlength="255" autocomplete="current-password" class="{{ $input }}">
            </label>
            <x-fastleo::button type="submit" variant="primary">{{ __('fastleo::profile.two_factor_enable') }}</x-fastleo::button>
        </form>
    @endif

    @error('current_password', 'two_factor')<p class="text-xs text-danger" role="alert">{{ $message }}</p>@enderror
    @error('code', 'two_factor')<p class="text-xs text-danger" role="alert">{{ $message }}</p>@enderror
</section>
