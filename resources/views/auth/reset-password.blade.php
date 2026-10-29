<x-fastleo::layouts.guest :title="__('fastleo::auth.reset_title')">
    <x-fastleo::auth-form :action="route('fastleo.password.update')">
        <input type="hidden" name="token" value="{{ $token }}">

        <h1 class="text-xl font-[650] tracking-[-0.02em]">{{ __('fastleo::auth.reset_heading') }}</h1>

        <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
            {{ __('fastleo::auth.email') }}
            <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username" maxlength="255" @class(['fastleo-input h-10 text-md', 'fastleo-input-invalid' => $errors->has('email')])>
        </label>

        <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
            {{ __('fastleo::auth.new_password') }}
            <input type="password" name="password" required autofocus autocomplete="new-password" maxlength="255" @class(['fastleo-input h-10 text-md', 'fastleo-input-invalid' => $errors->has('password')])>
        </label>

        <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
            {{ __('fastleo::auth.password_confirmation') }}
            <input type="password" name="password_confirmation" required autocomplete="new-password" maxlength="255" class="fastleo-input h-10 text-md">
        </label>

        <x-fastleo::button type="submit" variant="primary" size="lg" class="w-full">{{ __('fastleo::auth.reset_submit') }}</x-fastleo::button>
    </x-fastleo::auth-form>
</x-fastleo::layouts.guest>
