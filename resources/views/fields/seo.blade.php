{{-- Seo: заголовок, описание, картинка и запрет индексации одним блоком; счётчики длины и превью сниппета поиска. --}}
@php
    $host = parse_url((string) config('app.url'), PHP_URL_HOST);
    $host = is_string($host) ? $host : '';
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div
        x-data="fastleoSeo({ name: @js($name), titleMax: 60, descriptionMax: 160, emptyTitle: @js(__('fastleo::seo.preview_title')), emptyDescription: @js(__('fastleo::seo.preview_description')) })"
        data-seo="{{ $path }}"
        class="flex flex-col gap-5 rounded-lg border border-line bg-surface-2 p-4"
    >
        <div class="rounded-md border border-line bg-surface p-3 text-sm" data-seo-preview>
            <p class="mb-1 text-xs text-ink-2">{{ __('fastleo::seo.preview') }}</p>
            <div class="truncate text-xs text-ink-2">{{ $host }}</div>
            <div class="truncate text-base font-medium text-info" x-text="title.trim() || emptyTitle" data-seo-title></div>
            <div class="line-clamp-2 text-ink-2" x-text="description.trim() || emptyDescription" data-seo-description></div>
            <div class="mt-2 flex flex-wrap gap-x-4 text-xs">
                <span :class="title.length > titleMax ? 'text-warning ' : 'text-ink-2'" data-seo-title-count>
                    {{ __('fastleo::seo.title') }}: <span x-text="title.length"></span> / <span x-text="titleMax"></span> {{ __('fastleo::seo.characters') }}
                </span>
                <span :class="description.length > descriptionMax ? 'text-warning ' : 'text-ink-2'" data-seo-description-count>
                    {{ __('fastleo::seo.description') }}: <span x-text="description.length"></span> / <span x-text="descriptionMax"></span> {{ __('fastleo::seo.characters') }}
                </span>
            </div>
        </div>
        @include('fastleo::form.items', [
            'items' => $field->schema()->items(),
            'record' => $value,
            'namePrefix' => $name,
            'pathPrefix' => $path,
            'idPrefix' => \Illuminate\Support\Str::after($id, 'field-').'-',
        ])
    </div>
</x-fastleo::field>
