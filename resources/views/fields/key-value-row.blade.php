{{-- Одна строка KeyValue. --}}
@php
    $rowPath = $path.'.'.$key;
    $keyInvalid = $errors->has($rowPath.'.key');
    $valueInvalid = $errors->has($rowPath.'.value');
@endphp
<div data-block class="grid grid-cols-[auto_1fr_auto] items-start gap-2 sm:grid-cols-[auto_1fr_1fr_auto]">
    @if ($writable)
        <button type="button" data-block-handle class="mt-2.5 cursor-grab touch-none text-ink-3 hover:text-ink" aria-label="{{ __('fastleo::resources.nested_move') }}">
            <x-fastleo::icon name="grip-vertical" class="size-4" />
        </button>
    @else
        <span class="w-4"></span>
    @endif
    <div class="flex flex-col gap-1">
        <input type="text" name="{{ $name }}[{{ $key }}][key]" value="{{ $pair['key'] }}" maxlength="255" placeholder="{{ $field->getKeyLabel() }}" aria-label="{{ $field->getKeyLabel() }}" @readonly(! $writable) @class(['fastleo-input font-mono', 'fastleo-input-invalid' => $keyInvalid])>
        @if ($keyInvalid)<p class="text-sm text-danger">{{ $errors->first($rowPath.'.key') }}</p>@endif
    </div>
    <div class="col-start-2 flex flex-col gap-1 sm:col-start-auto">
        <input type="text" name="{{ $name }}[{{ $key }}][value]" value="{{ $pair['value'] }}" placeholder="{{ $field->getValueLabel() }}" aria-label="{{ $field->getValueLabel() }}" @readonly(! $writable) @class(['fastleo-input', 'fastleo-input-invalid' => $valueInvalid])>
        @if ($valueInvalid)<p class="text-sm text-danger">{{ $errors->first($rowPath.'.value') }}</p>@endif
    </div>
    @if ($writable)
        <button type="button" @click="remove($el)" class="col-start-3 row-start-1 mt-2.5 text-ink-3 hover:text-danger sm:col-start-auto" aria-label="{{ __('fastleo::resources.nested_remove') }}" data-block-remove>
            <x-fastleo::icon name="trash-2" class="size-4" />
        </button>
    @endif
</div>
