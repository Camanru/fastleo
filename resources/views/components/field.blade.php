@props(['field', 'id' => null, 'path' => null, 'inline' => false])
{{-- Обёртка поля формы: подпись, отметка обязательности, подсказка, ошибка. $path — ключ поля для ошибок (rows.0.title во вложенном блоке). --}}
@php
    $path ??= $field->name;
    $id ??= 'field-'.$field->name;
    $error = $errors->first($path) ?: $errors->first($path.'.*');
@endphp
<div {{ $attributes->class(['flex flex-col gap-1.5']) }} data-field="{{ $path }}">
    {{-- У многоязычного поля подпись — над вкладками языков (form.translatable). --}}
    @unless ($inline || $field->isTranslatable())
        <label for="{{ $id }}" class="text-sm font-medium text-ink-2">
            {{ $field->getLabel() }}
            @if ($field->isRequired())<span class="text-danger" aria-hidden="true">*</span>@endif
        </label>
    @endunless
    {{ $slot }}
    @if ($field->getHelp() && ! ($field->isTranslatable() && ! str_ends_with($path, '.'.\Fastleo\Fastleo::locales()[0])))
        <p id="{{ $id }}-help" class="text-xs text-ink-2">{{ $field->getHelp() }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="flex items-center gap-1.5 text-xs text-danger" role="alert">
            <x-fastleo::icon name="circle-alert" class="size-[13px] shrink-0" />
            {{ $error }}
        </p>
    @endif
</div>
