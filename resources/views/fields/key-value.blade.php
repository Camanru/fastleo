{{-- KeyValue: строки «название — значение»; добавить, удалить, перетащить. После ошибки — строки из old(). --}}
@php
    $writable = $field->isWritable();
    $old = old($path);
    $pairs = session()->hasOldInput() && $writable
        ? collect(is_array($old) ? $old : [])->map(fn ($pair) => ['key' => is_array($pair) && is_string($pair['key'] ?? null) ? $pair['key'] : '', 'value' => is_array($pair) && is_string($pair['value'] ?? null) ? $pair['value'] : ''])->all()
        : (is_array($value) ? $value : []);
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div
        x-data="fastleoBlocks({ min: 0, max: @js($field->getMax()), sortable: @js($writable) })"
        data-blocks="{{ $path }}"
        class="flex flex-col gap-2"
    >
        @if ($writable)
            <input type="hidden" name="{{ $name }}" value="">
        @endif
        <div class="hidden grid-cols-[auto_1fr_1fr_auto] gap-2 text-xs text-ink-2 sm:grid" aria-hidden="true">
            <span class="w-4"></span>
            <span>{{ $field->getKeyLabel() }}</span>
            <span>{{ $field->getValueLabel() }}</span>
            <span class="w-4"></span>
        </div>
        <div x-ref="list" class="flex flex-col gap-2">
            @foreach ($pairs as $key => $pair)
                @include('fastleo::fields.key-value-row', ['key' => (string) $key, 'pair' => $pair])
            @endforeach
        </div>
        @if ($writable)
            <template x-ref="template">
                @include('fastleo::fields.key-value-row', ['key' => '__KEY__', 'pair' => ['key' => '', 'value' => '']])
            </template>
            <div>
                <x-fastleo::button variant="dashed" icon="plus" x-show="max === null || count < max" x-on:click="add()" data-block-add>
                    {{ __('fastleo::resources.key_value_add') }}
                </x-fastleo::button>
            </div>
        @endif
    </div>
</x-fastleo::field>
