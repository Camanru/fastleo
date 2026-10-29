{{-- Password: всегда пустое; при редактировании пустое поле оставляет старый пароль. --}}
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <input
        type="password"
        id="{{ $id }}"
        name="{{ $name }}"
        autocomplete="new-password"
        @if ($field->getPlaceholder() || ($record?->exists ?? false)) placeholder="{{ $field->getPlaceholder() ?? __('fastleo::resources.password_keep') }}" @endif
        @required($field->isRequired() && ! ($record?->exists ?? false))
        @readonly($field->isReadonly())
        @disabled($field->isDisabled())
        @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        @class(['fastleo-input sm:max-w-md', 'fastleo-input-invalid' => $errors->has($path)])
    >
    @if ($field->isConfirmed())
        <input
            type="password"
            id="{{ $id }}-confirmation"
            name="{{ $name }}_confirmation"
            autocomplete="new-password"
            aria-label="{{ __('fastleo::resources.password_confirmation', ['label' => $field->getLabel()]) }}"
            placeholder="{{ __('fastleo::resources.password_repeat') }}"
            @readonly($field->isReadonly())
            @disabled($field->isDisabled())
            @class(['fastleo-input sm:max-w-md', 'fastleo-input-invalid' => $errors->has($path)])
        >
    @endif
</x-fastleo::field>
