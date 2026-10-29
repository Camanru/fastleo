{{-- Прогресс импорта и отчёт: страница опрашивает состояние раз в секунду, пока импорт не завершится. --}}
@php
    $slug = $resource::slug();
@endphp
<x-fastleo::layouts.app
    :title="__('fastleo::imports.title', ['name' => $resource::label()])"
    :back="['url' => route('fastleo.resources.index', $slug), 'label' => __('fastleo::imports.back')]"
>
    <div
        class="flex flex-col gap-4"
        x-data="fastleoImport({ url: @js(route('fastleo.resources.imports.show', [$slug, $import->id])), state: @js($import->progress()) })"
        data-import
    >
        <div class="fastleo-card flex flex-col gap-4 p-5">
        <div class="flex items-center gap-3">
            <template x-if="!state.finished">
                <x-fastleo::icon name="loader-circle" class="size-5 animate-spin text-primary-text" />
            </template>
            <template x-if="state.status === 'done'">
                <x-fastleo::icon name="circle-check" class="size-5 text-success" />
            </template>
            <template x-if="state.status === 'failed'">
                <x-fastleo::icon name="circle-alert" class="size-5 text-danger" />
            </template>
            <h2 class="text-lg font-semibold tracking-[-0.01em]" x-text="{ pending: @js(__('fastleo::imports.pending')), running: @js(__('fastleo::imports.running')), done: @js(__('fastleo::imports.done')), failed: @js(__('fastleo::imports.failed')) }[state.status]"></h2>
            <span class="text-sm text-ink-2">{{ $import->file_name }}</span>
        </div>

        <div class="flex h-2 gap-0.5 overflow-hidden rounded-full bg-sunken" role="img" aria-label="{{ __('fastleo::imports.processed') }}" x-show="state.processed > 0" data-import-bar>
            <span class="bg-success" :style="`flex: ${state.created} 0 0`"></span>
            <span class="bg-info" :style="`flex: ${state.updated} 0 0`"></span>
            <span class="bg-danger" :style="`flex: ${state.skipped} 0 0`"></span>
        </div>

        <p x-show="state.message" x-text="state.message" class="rounded-[10px] bg-warning-soft px-4 py-3 text-sm text-warning" role="alert"></p>

        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            @foreach (['processed', 'created', 'updated', 'skipped'] as $counter)
                <div class="rounded-[10px] border border-line bg-surface-2 px-3.5 py-3">
                    <dt class="flex items-center gap-1.5 text-ink-2">
                        @if ($counter !== 'processed')
                            <span @class(['size-1.5 rounded-full', 'bg-success' => $counter === 'created', 'bg-info' => $counter === 'updated', 'bg-danger' => $counter === 'skipped']) aria-hidden="true"></span>
                        @endif
                        {{ __('fastleo::imports.'.$counter) }}
                    </dt>
                    <dd @class(['text-2xl font-[650] tabular-nums', 'text-success' => $counter === 'created', 'text-info' => $counter === 'updated', 'text-danger' => $counter === 'skipped']) x-text="state.{{ $counter }}" data-counter="{{ $counter }}">{{ $import->{$counter} }}</dd>
                </div>
            @endforeach
        </dl>

        <p x-show="state.unknown_columns.length" class="text-sm text-ink-2" x-text="@js(__('fastleo::imports.unknown_columns', ['columns' => '__C__'])).replace('__C__', state.unknown_columns.join(', '))"></p>

        </div>

                <div x-show="state.errors.length" x-cloak class="fastleo-card overflow-hidden">
                    <h3 class="px-5 pt-4 pb-3 text-md font-semibold tracking-[-0.01em]">{{ __('fastleo::imports.errors_title') }}</h3>
                    <table class="w-full text-left text-sm">
                        <thead class="border-y border-line bg-surface-2 text-xs text-ink-2">
                            <tr>
                                <th scope="col" class="w-24 px-3 py-2 font-medium">{{ __('fastleo::imports.row') }}</th>
                                <th scope="col" class="px-3 py-2 font-medium">{{ __('fastleo::imports.error') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <template x-for="error in state.errors" :key="error.row">
                                <tr>
                                    <td class="px-3 py-2 tabular-nums" x-text="error.row"></td>
                                    <td class="px-3 py-2" x-text="error.message"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p x-show="state.errors.length >= {{ \Fastleo\Imports\Import::MAX_ERRORS }}" class="m-4 text-sm text-ink-2">
                        {{ __('fastleo::imports.errors_more', ['count' => \Fastleo\Imports\Import::MAX_ERRORS]) }}
                    </p>
                    <div x-show="state.errors_file" x-cloak class="m-4">
                        <x-fastleo::button :href="route('fastleo.resources.imports.errors', [$slug, $import->id])" icon="download" data-import-errors>
                            {{ __('fastleo::imports.errors_download') }}
                        </x-fastleo::button>
                    </div>
                </div>
    </div>
</x-fastleo::layouts.app>
