{{-- Code: код или HTML-вставка моноширинным шрифтом; Tab вставляет отступ. --}}
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <textarea
        id="{{ $id }}"
        name="{{ $name }}"
        rows="{{ $field->getRows() }}"
        x-data="fastleoCode"
        spellcheck="false"
        autocapitalize="none"
        wrap="off"
        @if ($field->getMaxLength()) maxlength="{{ $field->getMaxLength() }}" @endif
        @if ($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
        @required($field->isRequired())
        @readonly($field->isReadonly())
        @disabled($field->isDisabled())
        @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class(['fastleo-input font-mono text-sm sm:text-xs [tab-size:4]', 'fastleo-input-invalid' => $errors->has($path)])
    >{{ fastleo_old($path, $value) }}</textarea>
</x-fastleo::field>
