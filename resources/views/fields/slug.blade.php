{{-- Slug: адрес записи. С from() заполняется из поля-источника, пока его не правили вручную. --}}
@php
    $current = (string) fastleo_old($path, $value);
    // Многоязычный источник — поле основного языка (title[ru]).
    $source = (string) $field->getFrom();
    $generates = $field->getFrom() && $field->isWritable();
    $source .= isset($resource) && $resource->schema()->field($source)?->isTranslatable() ? '['.\Fastleo\Fastleo::locales()[0].']' : '';
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div class="relative" @if ($generates) x-data="fastleoSlug" data-source="{{ $source }}" @endif>
        <input
            type="text"
            id="{{ $id }}"
            name="{{ $name }}"
            value="{{ $current }}"
            maxlength="{{ $field->getMaxLength() }}"
            autocapitalize="none"
            spellcheck="false"
            x-ref="input"
            @if ($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
            @required($field->isRequired())
            @readonly($field->isReadonly())
            @disabled($field->isDisabled())
            @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            @class(['fastleo-input font-mono', 'pr-10' => $generates, 'fastleo-input-invalid' => $errors->has($path)])
        >
        @if ($generates)
            <button type="button" class="absolute top-1/2 right-1.5 flex size-7 -translate-y-1/2 items-center justify-center rounded-[6px] text-ink-2 hover:bg-hover hover:text-ink" @click="regenerate()" title="{{ __('fastleo::resources.slug_generate') }}">
                <span class="sr-only">{{ __('fastleo::resources.slug_generate') }}</span>
                <x-fastleo::icon name="refresh-cw" class="size-[15px]" />
            </button>
        @endif
    </div>
</x-fastleo::field>
