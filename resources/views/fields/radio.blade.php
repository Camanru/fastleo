{{-- Radio: один вариант кнопками-переключателями. --}}
@php
    $selected = (string) fastleo_old($path, $value);
    $radioOptions = $field->getOptions();
    // Сохранённое значение вне вариантов остаётся выбранным, пока админ не выберет другое.
    foreach ($field->savedValues($record) as $savedValue) {
        if (! array_key_exists($savedValue, $radioOptions)) {
            $radioOptions[$savedValue] = $savedValue;
        }
    }
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path" :inline="true">
    <fieldset
        @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        class="flex flex-col gap-2"
    >
        <legend class="mb-1 text-sm font-medium text-ink">
            {{ $field->getLabel() }}
            @if ($field->isRequired())<span class="text-danger" aria-hidden="true">*</span>@endif
        </legend>
        <div class="flex flex-wrap gap-x-5 gap-y-2">
            @foreach ($radioOptions as $optionValue => $optionLabel)
                <label class="flex items-center gap-2 text-sm text-ink">
                    <input
                        type="radio"
                        @if ($loop->first) id="{{ $id }}" @endif
                        name="{{ $name }}"
                        value="{{ $optionValue }}"
                        class="size-4 accent-primary"
                        @checked($selected === (string) $optionValue)
                        @required($field->isRequired())
                        @disabled($field->isDisabled() || $field->isReadonly())
                    >
                    {{ $optionLabel }}
                </label>
            @endforeach
        </div>
    </fieldset>
</x-fastleo::field>
