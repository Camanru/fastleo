{{-- Сообщения после действия: «Сохранено», понятная ошибка базы данных.
     Всплывают снизу справа; успех скрывается через 4 секунды, ошибку закрывают вручную. --}}
@php
    $messages = array_filter([
        'success' => session('fastleo.success'),
        'error' => session('fastleo.error'),
    ]);
@endphp
@if ($messages !== [])
    <div class="pointer-events-none fixed inset-x-4 bottom-[88px] z-50 flex flex-col items-end gap-2 lg:inset-x-auto lg:right-5 lg:bottom-5" aria-live="polite">
        @foreach ($messages as $tone => $message)
            <div
                x-data="{ shown: true }"
                x-show="shown"
                x-transition:enter="transition duration-200 ease-out-quint"
                x-transition:enter-start="translate-y-2 opacity-0"
                x-transition:leave="transition duration-150"
                x-transition:leave-end="opacity-0"
                @if ($tone === 'success') x-init="setTimeout(() => shown = false, 4000)" @endif
                class="pointer-events-auto flex w-full items-center gap-2.5 rounded-card bg-inverse py-2.5 pr-2 pl-3.5 text-base text-on-inverse shadow-pop lg:w-auto lg:max-w-md lg:min-w-[320px]"
                role="{{ $tone === 'error' ? 'alert' : 'status' }}"
                data-flash="{{ $tone }}"
            >
                <span @class(['flex size-[22px] shrink-0 items-center justify-center rounded-full text-white', 'bg-[#16794a]' => $tone === 'success', 'bg-[#d0342c]' => $tone === 'error'])>
                    <x-fastleo::icon :name="$tone === 'success' ? 'check' : 'circle-alert'" class="size-[13px]" />
                </span>
                <span class="min-w-0 flex-1 break-words">{{ $message }}</span>
                <button type="button" class="flex size-7 shrink-0 items-center justify-center rounded-[7px] opacity-60 hover:opacity-100" @click="shown = false" aria-label="{{ __('fastleo::layout.flash_close') }}">
                    <x-fastleo::icon name="x" class="size-[15px]" />
                </button>
            </div>
        @endforeach
    </div>
@endif
