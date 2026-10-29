{{-- Окно загрузки файла импорта в раздел. На телефоне — нижний лист. --}}
@php
    $importKey = $resource::importKey();
@endphp
<dialog id="fastleo-import" class="m-auto w-full max-w-[560px] overflow-hidden rounded-dialog border border-line bg-surface p-0 text-ink shadow-pop backdrop:bg-[rgb(10_12_16/.45)] backdrop:backdrop-blur-[3px] max-sm:mb-0 max-sm:max-w-none max-sm:rounded-b-none">
    <form method="POST" action="{{ route('fastleo.resources.import', $resource::slug()) }}" enctype="multipart/form-data" class="flex flex-col" x-data="{ name: '' }">
        @csrf
        <div class="flex items-center justify-between gap-3 px-5 pt-5">
            <h2 class="text-lg font-semibold tracking-[-0.01em]">{{ __('fastleo::imports.title', ['name' => $resource::label()]) }}</h2>
            <button type="button" class="flex size-8 items-center justify-center rounded-[8px] text-ink-2 hover:bg-hover hover:text-ink" x-on:click="$el.closest('dialog').close()">
                <span class="sr-only">{{ __('fastleo::resources.cancel') }}</span>
                <x-fastleo::icon name="x" class="size-4" />
            </button>
        </div>
        <div class="flex flex-col gap-3.5 px-5 pt-4 pb-5">
            <label class="flex cursor-pointer flex-col items-center gap-1.5 rounded-card border-[1.5px] border-dashed border-line-strong bg-surface-2 px-4 py-8 text-center focus-within:border-primary focus-within:shadow-[0_0_0_3px_var(--color-ring)]">
                <span class="mb-1 flex size-9 items-center justify-center rounded-[10px] bg-surface text-ink-2 shadow-card"><x-fastleo::icon name="upload" class="size-[18px]" /></span>
                <span class="text-base font-semibold" x-text="name || @js(__('fastleo::imports.drop'))">{{ __('fastleo::imports.drop') }}</span>
                <span class="text-sm text-ink-2">{{ __('fastleo::imports.choose') }}</span>
                <span class="text-xs text-ink-2">{{ __('fastleo::imports.hint_short', ['size' => round(config()->integer('fastleo.import.max_size', 10240) / 1024, 1)]) }}</span>
                <input type="file" name="file" accept=".xlsx,.csv" required class="sr-only" x-on:change="name = $event.target.files[0]?.name ?? ''">
            </label>
            <p class="text-sm text-ink-2">
                {{ __('fastleo::imports.hint', ['size' => round(config()->integer('fastleo.import.max_size', 10240) / 1024, 1), 'key' => $resource->newModel()->getKeyName()]) }}
                @if ($importKey !== null)
                    {{ __('fastleo::imports.hint_key', ['key' => $importKey]) }}
                @endif
            </p>
            @error('file')
                <p class="flex items-center gap-1.5 text-sm text-danger" role="alert"><x-fastleo::icon name="circle-alert" class="size-[13px] shrink-0" />{{ $message }}</p>
            @enderror
        </div>
        <div class="flex justify-end gap-2 border-t border-line bg-surface-2 px-5 py-3">
            <x-fastleo::button x-on:click="$el.closest('dialog').close()">{{ __('fastleo::resources.cancel') }}</x-fastleo::button>
            <x-fastleo::button type="submit" variant="primary" icon="upload">{{ __('fastleo::imports.upload') }}</x-fastleo::button>
        </div>
    </form>
</dialog>
