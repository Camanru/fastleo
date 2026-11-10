{{-- UserRoles: роли пользователя списком галочек. Пустое скрытое поле отправляет «ролей нет». --}}
@php
    $old = old($path, $value);
    $selected = array_values(array_filter(is_array($old) ? $old : [], 'is_string'));
    $roles = $field->getOptions();
    $writable = ! $field->isDisabled() && ! $field->isReadonly();
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    @if ($writable)
        <input type="hidden" name="{{ $name }}" value="">
    @endif
    <div
        id="{{ $id }}"
        role="group"
        @if ($errors->has($path) || $errors->has($path.'.*')) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        class="flex flex-wrap gap-2"
    >
        @foreach ($roles as $roleName => $roleLabel)
            <label class="flex cursor-pointer items-center gap-2 rounded-[10px] border border-line-strong bg-surface px-3 py-2 text-sm font-medium transition hover:bg-surface-2 has-checked:border-primary has-checked:bg-primary-soft">
                <input
                    type="checkbox"
                    @if ($writable) name="{{ $name }}[]" @endif
                    value="{{ $roleName }}"
                    class="size-4 rounded accent-primary"
                    @checked(in_array($roleName, $selected, true))
                    @disabled(! $writable)
                >
                {{ $roleLabel }}
            </label>
        @endforeach
    </div>
</x-fastleo::field>
