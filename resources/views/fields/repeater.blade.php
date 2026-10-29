{{-- Repeater: список элементов JSON-поля. После ошибки проверки элементы берутся из old(). --}}
@php
    $writable = $field->isWritable();
    $sortable = $writable;
    $old = old($path);
    $children = session()->hasOldInput() && $writable
        ? collect(is_array($old) ? $old : [])->map(fn () => null)
        : ($value instanceof \Illuminate\Support\Collection ? $value : collect());
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div
        x-data="fastleoBlocks({ min: @js($field->getMin() ?? 0), max: @js($field->getMax()), sortable: @js($sortable) })"
        data-blocks="{{ $path }}"
        class="flex flex-col gap-3"
    >
        @if ($writable)
            <input type="hidden" name="{{ $name }}" value="">
        @endif
        <div x-ref="list" class="flex flex-col gap-3">
            @foreach ($children as $key => $child)
                @include('fastleo::fields.nested-block', ['key' => (string) $key, 'child' => $child, 'blockId' => null])
            @endforeach
        </div>
        <p x-show="count === 0" @class(['text-sm text-ink-2 ', 'hidden' => $children->isNotEmpty()])>{{ __('fastleo::resources.nested_empty') }}</p>
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
</x-fastleo::field>
