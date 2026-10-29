{{-- File и Image: путь к файлу из менеджера. «Загрузить» кладёт файл в папку поля, «Выбрать из файлов» открывает окно менеджера. --}}
@php
    $old = old($path, $value);
    $current = is_string($old) && $old !== '' ? $old : null;
    $writable = $field->isWritable();
    $invalid = $errors->has($path);
    $config = [
        'path' => $current,
        'url' => $current !== null ? \Fastleo\Files\FileStore::url($current) : null,
        'image' => $field->onlyImages(),
        'folder' => $field->uploadFolder(),
        'picker' => $field->pickerUrl(),
        'crop' => $field instanceof \Fastleo\Fields\Image ? $field->getCrop() : null,
        'cropUrl' => route('fastleo.files.crop'),
    ];
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div x-data="fastleoFile(@js($config))" class="flex flex-wrap items-center gap-3" data-file-field>
        @if ($writable)
            <input type="hidden" name="{{ $name }}" :value="path ?? ''" value="{{ $current }}" x-ref="input">
        @endif

        @if ($field->onlyImages())
            <span @class(['flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-surface-2 ', 'border-danger' => $invalid, 'border-line ' => ! $invalid])>
                <img x-cloak x-show="path" :src="thumb(path)" alt="" class="size-full object-cover" data-file-preview>
                <x-fastleo::icon name="image" class="size-8 text-ink-3" x-show="! path" />
            </span>
        @endif

        <span class="flex min-w-0 flex-1 flex-col gap-2">
            <span class="truncate text-sm">
                <a x-cloak x-show="path" :href="url" target="_blank" rel="noopener" class="text-primary-text hover:underline" x-text="name()" data-file-name></a>
                <span x-show="! path" class="text-ink-2">{{ __('fastleo::files.no_file') }}</span>
            </span>

            @if ($writable)
                <span class="flex flex-wrap gap-2">
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-btn border border-line-strong bg-surface px-3 py-1.5 text-sm font-medium hover:bg-hover focus-within:shadow-[0_0_0_3px_var(--color-ring)]" :class="uploading && 'opacity-60 pointer-events-none'">
                        <x-fastleo::icon name="upload" class="size-4" />
                        <span x-text="uploading ? @js(__('fastleo::files.uploading')) : @js(__('fastleo::files.upload_file'))">{{ __('fastleo::files.upload_file') }}</span>
                        <input type="file" class="sr-only" @if ($field->onlyImages()) accept="{{ implode(',', array_map(fn ($e) => '.'.$e, $field->acceptedExtensions())) }}" @endif @change="upload($event.target.files); $event.target.value = ''" data-file-upload>
                    </label>
                    <x-fastleo::button id="{{ $id }}" icon="folder-open" class="py-1.5" @click="choose()" data-file-choose>{{ __('fastleo::files.choose_file') }}</x-fastleo::button>
                    @if ($config['crop'] !== null)
                        <x-fastleo::button icon="crop" class="py-1.5" x-cloak x-show="path" @click="crop()" ::disabled="uploading" data-file-crop>{{ __('fastleo::files.crop') }}</x-fastleo::button>
                    @endif
                    <x-fastleo::button variant="ghost" icon="x" class="py-1.5" x-cloak x-show="path" @click="set(null)" data-file-remove>{{ __('fastleo::files.remove_file') }}</x-fastleo::button>
                </span>
                <span x-cloak x-show="errors.length > 0" class="flex flex-col text-xs text-danger" role="alert">
                    <template x-for="error in errors"><span x-text="error"></span></template>
                </span>
            @endif
        </span>
    </div>
</x-fastleo::field>

@if ($writable && $config['crop'] !== null)
    @pushOnce('fastleo-scripts', 'fastleo-cropper')
        <link rel="stylesheet" href="{{ \Fastleo\Support\Assets::url('cropper.css') }}">
        <script defer src="{{ \Fastleo\Support\Assets::url('cropper.js') }}"></script>
        <dialog id="fastleo-cropper" class="m-auto w-[calc(100%-2rem)] max-w-4xl rounded-dialog bg-surface p-4 text-ink shadow-pop backdrop:bg-[rgb(10_12_16/.45)] backdrop:backdrop-blur-[3px]">
            <h2 class="font-semibold">{{ __('fastleo::files.crop_title') }}</h2>
            <p class="mt-1 text-sm text-ink-2">{{ __('fastleo::files.crop_help') }}</p>
            <div class="mt-3 h-[60vh] bg-sunken"><img src="" alt="" class="block max-w-full" data-cropper-image></div>
            <form method="dialog" class="mt-4 flex justify-end gap-2">
                <x-fastleo::button type="submit" value="cancel">{{ __('fastleo::resources.cancel') }}</x-fastleo::button>
                <x-fastleo::button type="submit" value="confirm" variant="primary" data-cropper-apply>{{ __('fastleo::files.crop_apply') }}</x-fastleo::button>
            </form>
        </dialog>
    @endPushOnce
@endif
