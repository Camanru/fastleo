<x-fastleo::layouts.guest :title="__('fastleo::auth.title')">
    {{-- Вход внутри окна выбора файла (сессия истекла): форма там не сработает как надо — входим в новой вкладке. --}}
    <div x-data="{ framed: window.top !== window }" x-cloak x-show="framed" class="flex flex-col gap-3 rounded-dialog border border-line bg-surface p-7 text-center shadow-pop" data-framed-login>
        <h1 class="text-lg font-semibold">{{ __('fastleo::files.framed_title') }}</h1>
        <p class="text-sm text-ink-2">{{ __('fastleo::files.framed_text') }}</p>
        <x-fastleo::button variant="primary" size="lg" :href="route('fastleo.login')" target="_blank" rel="noopener">{{ __('fastleo::files.login_new_tab') }}</x-fastleo::button>
    </div>

    <x-fastleo::auth-form x-data x-show="window.top === window" :action="route('fastleo.login.store')">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-[650] tracking-[-0.02em]">{{ __('fastleo::auth.heading') }}</h1>
            <p class="text-sm text-ink-2">{{ \Fastleo\Support\Branding::name() }} · {{ request()->getHost() }}</p>
        </div>

        <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
            {{ __('fastleo::auth.email') }}
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" @class(['fastleo-input h-10 text-md', 'fastleo-input-invalid' => $errors->has('email')])>
        </label>

        <div class="flex flex-col gap-1.5">
            <div class="flex items-baseline justify-between gap-3">
                <label for="login-password" class="text-sm font-medium text-ink-2">{{ __('fastleo::auth.password') }}</label>
                <a href="{{ route('fastleo.password.request') }}" class="text-sm font-medium text-primary-text hover:underline">{{ __('fastleo::auth.forgot_link') }}</a>
            </div>
            <div class="relative" x-data="{ shown: false }">
                <input type="password" :type="shown ? 'text' : 'password'" id="login-password" name="password" required autocomplete="current-password" @class(['fastleo-input h-10 pr-10 text-md', 'fastleo-input-invalid' => $errors->has('password')])>
                <button type="button" class="absolute top-1/2 right-2.5 -translate-y-1/2 text-ink-2 hover:text-ink" @click="shown = ! shown" :aria-pressed="shown" title="{{ __('fastleo::auth.password_toggle') }}">
                    <span class="sr-only">{{ __('fastleo::auth.password_toggle') }}</span>
                    <x-fastleo::icon name="eye" class="size-4" x-show="! shown" />
                    <x-fastleo::icon name="eye-off" class="size-4" x-cloak x-show="shown" />
                </button>
            </div>
        </div>

        <label class="flex items-center gap-2 text-base">
            <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded accent-primary">
            {{ __('fastleo::auth.remember') }}
        </label>

        <x-fastleo::button type="submit" variant="primary" size="lg" class="w-full">{{ __('fastleo::auth.submit') }}</x-fastleo::button>
    </x-fastleo::auth-form>
</x-fastleo::layouts.guest>
