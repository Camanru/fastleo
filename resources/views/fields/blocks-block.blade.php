{{-- Один блок конструктора: заголовок (ручка, тип, свернуть, удалить), скрытый тип и поля типа. --}}
@php
    $definition = $field->getTypes()[$type];
    $blockPath = $path.'.'.$key;
    $invalid = $errors->has($blockPath.'.*') || collect($errors->keys())->contains(fn ($error) => str_starts_with($error, $blockPath.'.'));
@endphp
<div data-block data-block-kind="{{ $type }}" x-data="{ open: true }" class="overflow-hidden rounded-[10px] border border-line-strong bg-surface shadow-card">
    <div class="flex h-11 items-center gap-2 pr-1.5 pl-2">
        @if ($writable)
            <button type="button" data-block-handle class="cursor-grab touch-none text-ink-3 hover:text-ink" aria-label="{{ __('fastleo::resources.nested_move') }}">
                <x-fastleo::icon name="grip-vertical" class="size-4" />
            </button>
        @endif
        <span class="flex size-7 shrink-0 items-center justify-center rounded-[7px] bg-primary-soft text-primary-text"><x-fastleo::icon :name="$definition->getIcon()" class="size-4" /></span>
        <span @class(['flex-1 text-xs font-semibold', 'text-danger' => $invalid, 'text-ink-2' => ! $invalid])>{{ $definition->getLabel() }}</span>
        <button type="button" class="flex size-[30px] items-center justify-center rounded-[7px] text-ink-3 hover:bg-hover hover:text-ink" x-on:click="open = ! open" :aria-expanded="open" aria-label="{{ __('fastleo::resources.blocks_toggle') }}">
            <x-fastleo::icon name="chevron-down" class="size-4 transition-transform" x-bind:class="open || 'rotate-90'" />
        </button>
        @if ($writable)
            <button type="button" x-show="count > min" @click="remove($el)" class="flex size-[30px] items-center justify-center rounded-[7px] text-ink-3 hover:bg-hover hover:text-danger" aria-label="{{ __('fastleo::resources.nested_remove') }}" data-block-remove>
                <x-fastleo::icon name="trash-2" class="size-4" />
            </button>
        @endif
    </div>
    <div x-show="open" class="flex flex-col gap-4 border-t border-line px-3.5 pt-3 pb-3.5">
        @if ($writable)
            <input type="hidden" name="{{ $name }}[{{ $key }}][type]" value="{{ $type }}">
        @endif
        @include('fastleo::form.items', [
            'items' => $field->schema($type)->items(),
            'record' => $item,
            'namePrefix' => $name.'['.$key.']['.$type.']',
            'pathPrefix' => $blockPath.'.'.$type,
            'idPrefix' => \Illuminate\Support\Str::after($id, 'field-').'-'.$key.'-',
        ])
    </div>
</div>
