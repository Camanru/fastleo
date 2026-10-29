{{-- time: <input type="time">. --}}
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <input
        type="time"
        id="{{ $id }}"
        name="{{ $name }}"
        value="{{ fastleo_old($path, $value) }}"
        @required($field->isRequired())
        @readonly($field->isReadonly())
        @disabled($field->isDisabled())
        @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class(['fastleo-input sm:max-w-xs', 'fastleo-input-invalid' => $errors->has($path)])
    >
</x-fastleo::field>
