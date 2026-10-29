{{-- Gallery: несколько картинок с порядком перетаскиванием. Пустое скрытое поле отправляет «картинок нет». --}}
@php
    $old = old($path, $value);
    $paths = array_values(array_filter(is_array($old) ? $old : [], fn ($item) => is_string($item) && $item !== ''));
    $writable = $field->isWritable();
    $config = [
        'folder' => $field->uploadFolder(),
        'picker' => $field->pickerUrl(multiple: true),
        'max' => $field->getMax(),
    ];
    $thumb = fn (string $file): string => route('fastleo.files.thumb', ['path' => $file]);
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div x-data="fastleoGallery(@js($config))" class="flex flex-col gap-2" data-gallery-field>
        @if ($writable)
            <input type="hidden" name="{{ $name }}" value="">
        @endif

        <ul x-ref="list" @class(['flex min-h-24 flex-wrap gap-2 rounded-md border border-dashed p-2', 'border-danger' => $errors->has($path) || $errors->has($path.'.*'), 'border-line-strong ' => ! ($errors->has($path) || $errors->has($path.'.*'))])>
            @foreach ($paths as $file)
                <li class="group relative size-24 overflow-hidden rounded-md bg-sunken" data-gallery-item>
                    <img src="{{ $thumb($file) }}" alt="" class="size-full object-cover @if ($writable) cursor-move @endif" title="{{ basename($file) }}">
                    @if ($writable)
                        <input type="hidden" name="{{ $name }}[]" value="{{ $file }}">
                        <button type="button" class="absolute top-1 right-1 rounded-md bg-white/90 p-1 text-ink shadow-sm hover:text-danger" @click="remove($el)" title="{{ __('fastleo::files.remove_file') }}">
                            <span class="sr-only">{{ __('fastleo::files.remove_file') }}</span>
                            <x-fastleo::icon name="x" class="size-3.5" />
                        </button>
                    @endif
                </li>
            @endforeach
            <li x-show="count === 0" class="flex items-center px-2 text-sm text-ink-2" data-gallery-empty>{{ __('fastleo::files.gallery_empty') }}</li>
        </ul>

        @if ($writable)
            <template x-ref="item">
                <li class="group relative size-24 overflow-hidden rounded-md bg-sunken" data-gallery-item>
                    <img src="" alt="" class="size-full cursor-move object-cover">
                    <input type="hidden" name="{{ $name }}[]" value="">
                    <button type="button" class="absolute top-1 right-1 rounded-md bg-white/90 p-1 text-ink shadow-sm hover:text-danger" @click="remove($el)" title="{{ __('fastleo::files.remove_file') }}">
                        <span class="sr-only">{{ __('fastleo::files.remove_file') }}</span>
                        <x-fastleo::icon name="x" class="size-3.5" />
                    </button>
                </li>
            </template>

            <span class="flex flex-wrap gap-2">
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-btn border border-line-strong bg-surface px-3 py-1.5 text-sm font-medium hover:bg-hover focus-within:shadow-[0_0_0_3px_var(--color-ring)]" :class="uploading && 'opacity-60 pointer-events-none'">
                    <x-fastleo::icon name="upload" class="size-4" />
                    <span x-text="uploading ? @js(__('fastleo::files.uploading')) : @js(__('fastleo::files.upload_file'))">{{ __('fastleo::files.upload_file') }}</span>
                    <input type="file" multiple class="sr-only" accept="{{ implode(',', array_map(fn ($e) => '.'.$e, $field->acceptedExtensions())) }}" @change="upload($event.target.files); $event.target.value = ''" data-gallery-upload>
                </label>
                <x-fastleo::button id="{{ $id }}" icon="folder-open" class="py-1.5" @click="choose()" data-gallery-choose>{{ __('fastleo::files.choose_file') }}</x-fastleo::button>
            </span>
            <span x-cloak x-show="errors.length > 0" class="flex flex-col text-xs text-danger" role="alert">
                <template x-for="error in errors"><span x-text="error"></span></template>
            </span>
        @endif
    </div>
</x-fastleo::field>
