<x-fastleo::layouts.guest :title="__('fastleo::auth.forgot_title')">
    <x-fastleo::auth-form :action="route('fastleo.password.email')">
        <div class="flex flex-col gap-1.5">
            <h1 class="text-xl font-[650] tracking-[-0.02em]">{{ __('fastleo::auth.forgot_heading') }}</h1>
            <p class="text-sm text-ink-2">{{ __('fastleo::auth.forgot_text') }}</p>
        </div>

        <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
            {{ __('fastleo::auth.email') }}
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" maxlength="255" @class(['fastleo-input h-10 text-md', 'fastleo-input-invalid' => $errors->has('email')])>
        </label>

        <x-fastleo::button type="submit" variant="primary" size="lg" class="w-full">{{ __('fastleo::auth.forgot_submit') }}</x-fastleo::button>

        <a href="{{ route('fastleo.login') }}" class="flex items-center justify-center gap-1.5 text-sm font-medium text-ink-2 hover:text-ink">
            <x-fastleo::icon name="arrow-left" class="size-3.5" />
            {{ __('fastleo::auth.back_to_login') }}
        </a>
    </x-fastleo::auth-form>
</x-fastleo::layouts.guest>
