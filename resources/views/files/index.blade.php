{{-- Файловый менеджер: папки и файлы корня загрузок. В окне выбора ($picker) — без меню; клик по файлу выбирает его. --}}
@php
    $folderUrl = fn (string $path): string => route('fastleo.files.index', [...$query, 'folder' => $path]);
    $human = fn (int $bytes): string => \Fastleo\Files\UploadLimits::human($bytes);
    $messages = [
        'too_big' => __('fastleo::files.too_big'),
        'too_big_total' => __('fastleo::files.too_big_total'),
        'error' => __('fastleo::files.error'),
        'session_expired' => __('fastleo::files.session_expired'),
        'copied' => __('fastleo::files.copied'),
        'delete_confirm' => __('fastleo::files.delete_confirm'),
        'sizes' => [__('fastleo::files.size_bytes'), __('fastleo::files.size_kb'), __('fastleo::files.size_mb'), __('fastleo::files.size_gb')],
    ];
@endphp
<x-dynamic-component :component="$picker ? 'fastleo::layouts.frame' : 'fastleo::layouts.app'" :title="__('fastleo::files.title')">
    <x-slot:actions></x-slot:actions>
    <div
        x-data="fastleoFiles"
        data-folder="{{ $folder }}"
        data-upload-url="{{ route('fastleo.files.upload') }}"
        data-folder-url="{{ route('fastleo.files.folder') }}"
        data-delete-url="{{ route('fastleo.files.destroy') }}"
        data-login-url="{{ route('fastleo.login') }}"
        data-max-file="{{ $limits['file'] }}"
        data-max-total="{{ $limits['total'] }}"
        data-picker="{{ $picker }}"
        data-multiple="{{ $multiple ? '1' : '' }}"
        data-messages="{{ json_encode($messages, JSON_UNESCAPED_UNICODE) }}"
        class="relative flex flex-col gap-4"
        @dragover.prevent="dragging = true"
        @dragleave.self="dragging = false"
        @drop.prevent="drop($event)"
        data-files
    >
        {{-- Вне окна выбора кнопки уходят в шапку страницы (x-teleport), а строка остаётся только для крошек вложенной папки. --}}
        <div @class(['flex flex-wrap items-center gap-2', 'hidden' => count($breadcrumbs) < 2 && ! $picker])>
            <nav aria-label="{{ __('fastleo::files.title') }}" class="flex min-w-0 flex-1 flex-wrap items-center gap-1 text-sm">
                @foreach ($breadcrumbs as $crumb)
                    @if (! $loop->first)
                        <x-fastleo::icon name="chevron-right" class="size-4 shrink-0 text-ink-3" />
                    @endif
                    @if ($loop->last)
                        <span class="font-semibold" aria-current="page">{{ $crumb['name'] }}</span>
                    @else
                        <a href="{{ $folderUrl($crumb['path']) }}" class="text-primary-text hover:underline" @click="go($event)">{{ $crumb['name'] }}</a>
                    @endif
                @endforeach
            </nav>

            @unless ($picker)<template x-teleport="#fastleo-page-actions">@endunless
            <div class="flex flex-wrap items-center gap-2" data-files-toolbar>
                <label class="relative w-full sm:w-56">
                    <span class="sr-only">{{ __('fastleo::files.filter') }}</span>
                    <x-fastleo::icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-3" />
                    <input type="search" x-model="query" placeholder="{{ __('fastleo::files.filter') }}" class="fastleo-input pl-9" data-files-filter>
                </label>

                <x-fastleo::button icon="folder-plus" @click="folderOpen = ! folderOpen; $nextTick(() => $refs.folderName?.focus())">{{ __('fastleo::files.new_folder') }}</x-fastleo::button>

                <label class="inline-flex h-9 cursor-pointer items-center justify-center gap-2 rounded-btn bg-primary px-3.5 text-base font-[550] whitespace-nowrap text-white shadow-[inset_0_1px_0_rgb(255_255_255/.16),0_1px_2px_rgb(0_0_0/.12)] hover:bg-primary-hover focus-within:shadow-[0_0_0_3px_var(--color-ring)]" :class="uploading && 'opacity-60 pointer-events-none'">
                    <x-fastleo::icon name="upload" class="size-4 shrink-0" />
                    <span x-text="uploading ? @js(__('fastleo::files.uploading')) : @js(__('fastleo::files.upload'))">{{ __('fastleo::files.upload') }}</span>
                    <input type="file" multiple class="sr-only" @change="upload($event.target.files); $event.target.value = ''" data-files-input>
                </label>

                @unless ($picker)
                    <x-fastleo::button variant="danger" icon="trash-2" x-cloak x-show="selected.length > 0" @click="removeSelected()" data-files-delete>
                        {{ __('fastleo::files.delete_selected') }} (<span x-text="selected.length"></span>)
                    </x-fastleo::button>
                @endunless
            </div>
            @unless ($picker)</template>@endunless
        </div>

        @unless ($picker)
            <div class="flex items-center gap-3 rounded-card border-[1.5px] border-dashed border-line-strong bg-surface-2 px-4 py-3.5">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-surface text-ink-2 shadow-card"><x-fastleo::icon name="upload" class="size-[18px]" /></span>
                <div class="flex min-w-0 flex-col">
                    <span class="text-sm font-medium">{{ __('fastleo::files.drop_hint') }}</span>
                    <span class="text-xs text-ink-2 empty:hidden">
                        @if ($limits['file'] > 0 && $limits['file'] === $limits['total'])
                            {{ __('fastleo::files.limits_same', ['file' => $human($limits['file'])]) }}
                        @elseif ($limits['file'] > 0 && $limits['total'] > 0)
                            {{ __('fastleo::files.limits', ['file' => $human($limits['file']), 'total' => $human($limits['total'])]) }}
                        @elseif ($limits['file'] > 0)
                            {{ __('fastleo::files.limits_file', ['file' => $human($limits['file'])]) }}
                        @endif
                    </span>
                </div>
            </div>
        @endunless

        <form x-cloak x-show="folderOpen" @submit.prevent="createFolder()" class="flex max-w-md items-center gap-2">
            <input type="text" x-ref="folderName" x-model="folderName" maxlength="100" placeholder="{{ __('fastleo::files.folder_placeholder') }}" class="fastleo-input" data-folder-name>
            <x-fastleo::button type="submit" variant="primary">{{ __('fastleo::files.create') }}</x-fastleo::button>
            <x-fastleo::button variant="ghost" @click="folderOpen = false">{{ __('fastleo::files.cancel') }}</x-fastleo::button>
        </form>

        <div x-cloak x-show="errors.length > 0" class="rounded-md bg-danger-soft px-4 py-3 text-sm text-danger" role="alert" data-files-errors>
            <ul class="flex flex-col gap-1">
                <template x-for="error in errors"><li x-text="error"></li></template>
            </ul>
            <a x-cloak x-show="expired" href="{{ route('fastleo.login') }}" target="_blank" rel="noopener" class="mt-2 inline-block font-medium underline">{{ __('fastleo::files.login_new_tab') }}</a>
        </div>
        <p x-cloak x-show="notice !== ''" x-text="notice" class="text-sm text-success" role="status"></p>

        <div data-files-content>
            @if ($listing['folders'] === [] && $listing['files'] === [])
                <p class="fastleo-card px-4 py-10 text-center text-sm text-ink-2">{{ __('fastleo::files.empty') }}</p>
            @else
                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                    @foreach ($listing['folders'] as $entry)
                        <li x-show="matches(@js($entry['name']))" class="fastleo-card group relative flex flex-col overflow-hidden" data-entry="{{ $entry['path'] }}" data-name="{{ $entry['name'] }}">
                            <a href="{{ $folderUrl($entry['path']) }}" @click="go($event)" class="flex flex-1 flex-col text-left hover:bg-hover">
                                <span class="flex aspect-[4/3] w-full items-center justify-center bg-primary-soft text-primary-text">
                                    <x-fastleo::icon name="folder" class="size-[34px]" />
                                </span>
                                <span class="truncate px-3 py-2.5 text-sm font-medium" title="{{ $entry['name'] }}">{{ $entry['name'] }}</span>
                            </a>
                            @unless ($picker)
                                <input type="checkbox" class="absolute top-2 left-2 opacity-0 group-hover:opacity-100 checked:opacity-100 focus-visible:opacity-100 max-md:opacity-100" aria-label="{{ $entry['name'] }}" :checked="selected.includes(@js($entry['path']))" @change="toggle(@js($entry['path']))">
                            @endunless
                        </li>
                    @endforeach
                    @foreach ($listing['files'] as $file)
                        <li
                            x-show="matches(@js($file['name']))"
                            @class(['fastleo-card group relative flex flex-col overflow-hidden', 'border-primary shadow-[0_0_0_3px_var(--color-ring)]' => $file['path'] === $current])
                            :class="selected.includes(@js($file['path'])) && 'border-primary shadow-[0_0_0_3px_var(--color-ring)]'"
                            data-entry="{{ $file['path'] }}"
                            data-name="{{ $file['name'] }}"
                            @if ($file['path'] === $current) data-current @endif
                        >
                            <button
                                type="button"
                                class="flex flex-1 flex-col text-left hover:bg-hover"
                                @click="activate(@js(['path' => $file['path'], 'url' => $file['url'], 'name' => $file['name'], 'image' => $file['image']]))"
                                title="{{ $file['name'] }}"
                            >
                                <span class="flex aspect-[4/3] w-full items-center justify-center bg-sunken">
                                    @if ($file['image'])
                                        <img src="{{ route('fastleo.files.thumb', ['path' => $file['path']]) }}" alt="" loading="lazy" class="size-full object-cover">
                                    @else
                                        <span class="flex flex-col items-center gap-1 text-ink-3">
                                            <x-fastleo::icon name="file" class="size-10" />
                                            <span class="text-xs font-semibold uppercase">{{ pathinfo($file['name'], PATHINFO_EXTENSION) }}</span>
                                        </span>
                                    @endif
                                </span>
                                <span class="flex flex-col px-3 py-2">
                                    <span class="truncate text-sm font-medium">{{ $file['name'] }}</span>
                                    <span class="text-xs text-ink-2">{{ $human($file['size']) }}</span>
                                </span>
                            </button>
                            @if (! $picker || $multiple)
                                <span class="pointer-events-none absolute top-2 left-2 flex size-4 items-center justify-center rounded-[4px] border-[1.5px] opacity-0 group-hover:opacity-100 max-md:opacity-100" :class="selected.includes(@js($file['path'])) ? '!opacity-100 border-primary bg-primary text-white' : 'border-line-strong bg-surface'" aria-hidden="true">
                                    <span class="flex" x-show="selected.includes(@js($file['path']))"><x-fastleo::icon name="check" class="size-3" /></span>
                                </span>
                            @endif
                            <span class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100 max-md:opacity-100">
                                <button type="button" class="rounded-[7px] bg-surface/90 p-1.5 text-ink-2 shadow-card hover:text-ink" title="{{ __('fastleo::files.copy_link') }}" @click.stop="copy(@js($file['url']))">
                                    <span class="sr-only">{{ __('fastleo::files.copy_link') }}</span>
                                    <x-fastleo::icon name="copy" class="size-4" />
                                </button>
                                <a href="{{ $file['url'] }}" target="_blank" rel="noopener" class="rounded-[7px] bg-surface/90 p-1.5 text-ink-2 shadow-card hover:text-ink" title="{{ __('fastleo::files.open') }}">
                                    <span class="sr-only">{{ __('fastleo::files.open') }}</span>
                                    <x-fastleo::icon name="external-link" class="size-4" />
                                </a>
                            </span>
                        </li>
                    @endforeach
                </ul>
                <p x-cloak x-show="noMatches()" class="px-4 py-6 text-center text-sm text-ink-2">{{ __('fastleo::files.nothing_found') }}</p>
            @endif
        </div>

        @if ($multiple)
            <div x-cloak x-show="selected.length > 0" class="sticky bottom-0 flex justify-end border-t border-line bg-surface-2/95 py-3">
                <x-fastleo::button variant="primary" icon="check" @click="chooseSelected()" data-files-choose>
                    {{ __('fastleo::files.select') }} (<span x-text="selected.length"></span>)
                </x-fastleo::button>
            </div>
        @endif

        <div x-cloak x-show="dragging" class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-card border-2 border-dashed border-primary bg-primary-soft text-sm font-medium text-primary-text">
            {{ __('fastleo::files.drop') }}
        </div>
    </div>
</x-dynamic-component>
