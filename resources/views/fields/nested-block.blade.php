{{-- Один блок HasMany: ручка перетаскивания, кнопка удаления, скрытый id своей записи и поля блока. --}}
<div data-block class="flex flex-col gap-4 rounded-[10px] border border-line-strong bg-surface p-3.5 shadow-card">
    @if ($writable)
        <div class="-my-1 flex items-center gap-2">
            @if ($sortable)
                <button type="button" data-block-handle class="cursor-grab touch-none text-ink-3 hover:text-ink" aria-label="{{ __('fastleo::resources.nested_move') }}">
                    <x-fastleo::icon name="grip-vertical" class="size-4" />
                </button>
            @endif
            <div class="flex-1"></div>
            <button type="button" x-show="count > min" @click="remove($el)" class="text-ink-3 hover:text-danger" aria-label="{{ __('fastleo::resources.nested_remove') }}" data-block-remove>
                <x-fastleo::icon name="trash-2" class="size-4" />
            </button>
        </div>
    @endif
    @if ($blockId !== null)
        <input type="hidden" name="{{ $name }}[{{ $key }}][id]" value="{{ $blockId }}">
    @endif
    @include('fastleo::form.items', [
        'items' => $field->schema()->items(),
        'record' => $child,
        'namePrefix' => $name.'['.$key.']',
        'pathPrefix' => $path.'.'.$key,
        'idPrefix' => \Illuminate\Support\Str::after($id, 'field-').'-'.$key.'-',
    ])
</div>
