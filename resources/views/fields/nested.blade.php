{{-- HasMany / HasOne: вложенные записи в форме родителя. После ошибки проверки блоки берутся только из old():
     если админ удалил все блоки, они не возвращаются из базы. --}}
@php
    $children = $value instanceof \Illuminate\Support\Collection ? $value : collect();
    $old = old($path);
    $blocks = [];
    $writable = $field->isWritable();

    if ($field->isSingle()) {
        $blocks[] = ['key' => null, 'child' => $children->first(), 'blockId' => null];
    } elseif ($writable && session()->hasOldInput()) {
        foreach (is_array($old) ? $old : [] as $key => $block) {
            $blockId = is_array($block) && isset($block['id']) && is_scalar($block['id']) ? (string) $block['id'] : null;
            $child = $blockId === null ? null : $children->first(fn ($child) => (string) $child->getKey() === $blockId);
            $blocks[] = ['key' => (string) $key, 'child' => $child, 'blockId' => $blockId];
        }
    } else {
        foreach ($children->values() as $index => $child) {
            $blocks[] = ['key' => (string) $index, 'child' => $child, 'blockId' => (string) $child->getKey()];
        }
    }

    $sortable = ! $field->isSingle() && $writable && $field->getOrderColumn() !== null;
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    @if ($field->isSingle())
        <div class="rounded-lg border border-line p-4" data-blocks="{{ $path }}">
            <div class="flex flex-col gap-4">
                @include('fastleo::form.items', [
                    'items' => $field->schema()->items(),
                    'record' => $blocks[0]['child'],
                    'namePrefix' => $name,
                    'pathPrefix' => $path,
                    'idPrefix' => \Illuminate\Support\Str::after($id, 'field-').'-',
                ])
            </div>
        </div>
    @else
        <div
            x-data="fastleoBlocks({ min: @js($field->getMin() ?? 0), max: @js($field->getMax()), sortable: @js($sortable) })"
            data-blocks="{{ $path }}"
            class="flex flex-col gap-3"
        >
            @if ($writable)
                <input type="hidden" name="{{ $name }}" value="">
            @endif
            <div x-ref="list" class="flex flex-col gap-3">
                @foreach ($blocks as $block)
                    @include('fastleo::fields.nested-block', $block)
                @endforeach
            </div>
            <p x-show="count === 0" @class(['text-sm text-ink-2 ', 'hidden' => $blocks !== []])>{{ __('fastleo::resources.nested_empty') }}</p>
            @if ($writable)
                <template x-ref="template">
                    @include('fastleo::fields.nested-block', ['key' => '__KEY__', 'child' => null, 'blockId' => null])
                </template>
                <div>
                    <x-fastleo::button variant="dashed" icon="plus" x-show="max === null || count < max" x-on:click="add()" data-block-add>
                        {{ __('fastleo::resources.nested_add') }}
                    </x-fastleo::button>
                </div>
            @endif
        </div>
    @endif
</x-fastleo::field>
