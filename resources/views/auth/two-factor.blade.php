<x-fastleo::layouts.guest :title="__('fastleo::auth.two_factor_title')">
    <x-fastleo::auth-form :action="route('fastleo.two-factor.challenge.store')" x-data="fastleoLoginCode">
        <div class="flex flex-col items-center gap-3 text-center">
            <span class="flex size-11 items-center justify-center rounded-full bg-primary-soft text-primary-text">
                <x-fastleo::icon name="shield-check" class="size-5" />
            </span>
            <div class="flex flex-col gap-1.5">
                <h1 class="text-xl font-[650] tracking-[-0.02em]">{{ __('fastleo::auth.two_factor_heading') }}</h1>
                <p class="text-sm text-ink-2">{{ __('fastleo::auth.two_factor_text') }}</p>
            </div>
        </div>

        <div class="flex flex-col gap-1.5">
            <label for="two-factor-code" class="text-sm font-medium text-ink-2">{{ __('fastleo::auth.two_factor_code') }}</label>

            {{-- Шесть ячеек: настоящее поле лежит поверх прозрачным, поэтому работают ввод, вставка и автозаполнение кода из СМС/менеджера паролей. --}}
            <div x-show="! recovery" class="relative">
                <div class="grid grid-cols-6 gap-2" aria-hidden="true">
                    <template x-for="position in 6" :key="position">
                        <div
                            class="flex h-[52px] items-center justify-center rounded-[10px] border bg-surface text-[22px] font-semibold tabular-nums transition"
                            :class="focused && digits.length === position - 1 || focused && digits.length === 6 && position === 6 ? 'border-primary shadow-[0_0_0_3px_var(--color-ring)]' : 'border-line-strong'"
                            data-code-cell
                            x-text="digits[position - 1] ?? ''"
                        ></div>
                    </template>
                </div>
                <input
                    type="text"
                    id="two-factor-code"
                    name="code"
                    x-ref="code"
                    :value="digits"
                    :disabled="recovery"
                    @input="digits = $event.target.value.replace(/\D/g, '').slice(0, 6); $event.target.value = digits"
                    @focus="focused = true"
                    @blur="focused = false"
                    required
                    autofocus
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    maxlength="6"
                    class="absolute inset-0 size-full cursor-text opacity-0"
                >
            </div>

            <input
                x-cloak
                x-show="recovery"
                type="text"
                name="code"
                :disabled="! recovery"
                x-ref="recoveryCode"
                autocomplete="off"
                maxlength="64"
                class="fastleo-input h-10 font-mono text-md"
            >
        </div>

        <x-fastleo::button type="submit" variant="primary" size="lg" class="w-full">{{ __('fastleo::auth.two_factor_submit') }}</x-fastleo::button>

        <div class="flex items-center justify-between gap-3 text-sm font-medium">
            <a href="{{ route('fastleo.login') }}" class="flex items-center gap-1.5 text-ink-2 hover:text-ink">
                <x-fastleo::icon name="arrow-left" class="size-3.5" />
                {{ __('fastleo::auth.back_to_login') }}
            </a>
            <button type="button" class="text-primary-text hover:underline" @click="toggleRecovery()" x-text="recovery ? @js(__('fastleo::auth.two_factor_use_app')) : @js(__('fastleo::auth.two_factor_use_recovery'))">{{ __('fastleo::auth.two_factor_use_recovery') }}</button>
        </div>
    </x-fastleo::auth-form>
</x-fastleo::layouts.guest>
