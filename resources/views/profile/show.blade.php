{{-- Профиль администратора: данные, пароль, двухфакторная защита, устройства. $user, $devices, $currentDevice, $twoFactor*, $recoveryLeft. --}}
@php($input = 'fastleo-input')
@php($card = 'fastleo-card flex flex-col gap-4 p-5')
<x-fastleo::layouts.app :title="__('fastleo::profile.title')" :description="__('fastleo::profile.description')">
    <x-fastleo::flash />

    <div class="grid items-start gap-4 lg:grid-cols-2">
        <div class="flex min-w-0 flex-col gap-4">
        {{-- Текущий пароль нужен только для смены email — поле появляется, когда email изменён (или после ошибки). --}}
        <form method="POST" action="{{ route('fastleo.profile.update') }}" class="{{ $card }}" data-profile-data x-data="{ email: @js(old('email', $user->getAttribute('email'))), initial: @js($user->getAttribute('email')) }">
            @csrf
            @method('PUT')
            <h2 class="text-md font-semibold tracking-[-0.01em]">{{ __('fastleo::profile.data_heading') }}</h2>

            <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                {{ __('fastleo::profile.name') }}
                <input type="text" name="name" value="{{ old('name', $user->getAttribute('name')) }}" required maxlength="255" autocomplete="name" class="{{ $input }}">
                @error('name', 'profile')<span class="text-xs font-normal text-danger" role="alert">{{ $message }}</span>@enderror
            </label>

            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                {{ __('fastleo::profile.email') }}
                <input type="email" name="email" x-model="email" value="{{ old('email', $user->getAttribute('email')) }}" required maxlength="255" autocomplete="email" class="{{ $input }}">
                @error('email', 'profile')<span class="text-xs font-normal text-danger" role="alert">{{ $message }}</span>@enderror
            </label>
            </div>

            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2" x-show="email.trim().toLowerCase() !== String(initial).toLowerCase() || @js($errors->profile->has('current_password'))" @unless ($errors->profile->has('current_password')) x-cloak @endunless>
                {{ __('fastleo::profile.current_password') }}
                <input type="password" name="current_password" maxlength="255" autocomplete="current-password" class="{{ $input }}" data-profile-data-password>
                <span class="text-xs font-normal text-ink-2">{{ __('fastleo::profile.email_help') }}</span>
                @error('current_password', 'profile')<span class="text-xs font-normal text-danger" role="alert">{{ $message }}</span>@enderror
            </label>

            <div><x-fastleo::button type="submit" size="sm">{{ __('fastleo::profile.save') }}</x-fastleo::button></div>
        </form>

        <form method="POST" action="{{ route('fastleo.profile.password') }}" class="{{ $card }}" data-profile-password>
            @csrf
            @method('PUT')
            <h2 class="text-md font-semibold tracking-[-0.01em]">{{ __('fastleo::profile.password_heading') }}</h2>
            <p class="text-sm text-ink-2">{{ __('fastleo::profile.password_help') }}</p>

            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                {{ __('fastleo::profile.current_password') }}
                <input type="password" name="current_password" required maxlength="255" autocomplete="current-password" class="{{ $input }}">
                @error('current_password', 'password')<span class="text-xs font-normal text-danger" role="alert">{{ $message }}</span>@enderror
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                {{ __('fastleo::profile.new_password') }}
                <input type="password" name="password" required maxlength="255" autocomplete="new-password" class="{{ $input }}">
                @error('password', 'password')<span class="text-xs font-normal text-danger" role="alert">{{ $message }}</span>@enderror
            </label>

            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                {{ __('fastleo::profile.password_confirmation') }}
                <input type="password" name="password_confirmation" required maxlength="255" autocomplete="new-password" class="{{ $input }}">
            </label>
            </div>

            <div><x-fastleo::button type="submit" variant="primary">{{ __('fastleo::profile.password_save') }}</x-fastleo::button></div>
        </form>
        </div>

        @include('fastleo::profile.two-factor')

        <section class="{{ $card }} lg:col-span-2" data-profile-devices>
            <div>
                <h2 class="text-md font-semibold tracking-[-0.01em]">{{ __('fastleo::profile.devices_heading') }}</h2>
                <p class="text-sm text-ink-2">{{ __('fastleo::profile.devices_help') }}</p>
            </div>

            <ul class="flex flex-col divide-y divide-line">
                @forelse ($devices as $device)
                    <li class="flex flex-col gap-0.5 py-3 text-sm" data-device>
                        <span class="font-medium break-words">
                            {{ $device->user_agent ?? __('fastleo::profile.device_unknown') }}
                            @if ($device->fingerprint === $currentDevice)
                                <x-fastleo::badge tone="info" class="ml-2" :dot="false">{{ __('fastleo::profile.device_current') }}</x-fastleo::badge>
                            @endif
                        </span>
                        <span class="text-ink-2">
                            {{ $device->ip }} · {{ __('fastleo::profile.device_last_seen', ['time' => $device->last_seen_at?->timezone(\Fastleo\Fastleo::timezone())->format('d.m.Y H:i')]) }}
                        </span>
                    </li>
                @empty
                    <li class="py-3 text-sm text-ink-2">{{ __('fastleo::profile.devices_empty') }}</li>
                @endforelse
            </ul>

            <form method="POST" action="{{ route('fastleo.profile.logout-others') }}" class="flex flex-col gap-3 border-t border-line pt-4" data-profile-logout-others>
                @csrf
                <p class="text-sm text-ink-2">{{ __('fastleo::profile.logout_others_help') }}</p>
                <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                    {{ __('fastleo::profile.current_password') }}
                    <input type="password" name="current_password" required maxlength="255" autocomplete="current-password" class="{{ $input }}">
                    @error('current_password', 'sessions')<span class="text-xs font-normal text-danger" role="alert">{{ $message }}</span>@enderror
                </label>
                <div><x-fastleo::button type="submit" icon="log-out">{{ __('fastleo::profile.logout_others') }}</x-fastleo::button></div>
            </form>
        </section>
    </div>
</x-fastleo::layouts.app>
