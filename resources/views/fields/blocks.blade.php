{{-- Blocks: конструктор из блоков разных типов. Поля блока отправляются под ключом его типа:
     content[0][type]=text, content[0][text][body]=…. После ошибки проверки блоки берутся из old(). --}}
@php
    $writable = $field->isWritable();
    $old = old($path);
    $blocks = [];

    if (session()->hasOldInput() && $writable) {
        foreach (is_array($old) ? $old : [] as $key => $block) {
            $type = is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : null;

            if ($type !== null && isset($field->getTypes()[$type])) {
                $blocks[] = ['key' => (string) $key, 'type' => $type, 'item' => null];
            }
        }
    } else {
        foreach (is_array($value) ? $value : [] as $index => $block) {
            $blocks[] = ['key' => (string) $index, 'type' => $block['type'], 'item' => $block['item']];
        }
    }
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div
        x-data="fastleoBlocks({ min: @js($field->getMin() ?? 0), max: @js($field->getMax()), sortable: @js($writable) })"
        data-blocks="{{ $path }}"
        class="flex flex-col gap-3"
    >
        @if ($writable)
            <input type="hidden" name="{{ $name }}" value="">
        @endif
        <div x-ref="list" class="flex flex-col gap-3">
            @foreach ($blocks as $block)
                @include('fastleo::fields.blocks-block', $block)
            @endforeach
        </div>
        <p x-show="count === 0" @class(['text-sm text-ink-2 ', 'hidden' => $blocks !== []])>{{ __('fastleo::resources.blocks_empty') }}</p>
        @if ($writable)
            @foreach ($field->getTypes() as $type => $definition)
                <template x-ref="template-{{ $type }}">
                    @include('fastleo::fields.blocks-block', ['key' => '__KEY__', 'type' => $type, 'item' => null])
                </template>
            @endforeach
            <div x-data="fastleoMenu" class="relative" @keydown.escape="menu = false" @click.outside="menu = false" @scroll.window="place()" x-show="max === null || count < max">
                <x-fastleo::button variant="dashed" icon="plus" x-ref="button" x-on:click="toggle()" x-bind:aria-expanded="menu" aria-haspopup="menu" data-block-add>
                    {{ __('fastleo::resources.blocks_add') }}
                </x-fastleo::button>
                <div x-show="menu" x-cloak role="menu" :style="style" class="fixed z-40 grid w-[min(28rem,calc(100vw-1rem))] grid-cols-3 gap-0.5 rounded-card border border-line bg-surface p-1.5 text-base shadow-pop sm:grid-cols-4">
                    @foreach ($field->getTypes() as $type => $definition)
                        <button type="button" role="menuitem" class="flex flex-col items-center gap-1.5 rounded-[8px] px-1 py-3 text-xs font-medium text-ink hover:bg-hover" x-on:click="menu = false; add(@js($type))" data-block-type="{{ $type }}">
                            <x-fastleo::icon :name="$definition->getIcon()" class="size-[18px] shrink-0 text-ink-2" />
                            {{ $definition->getLabel() }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-fastleo::field>
