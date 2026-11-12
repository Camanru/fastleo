{{-- Один блок конструктора: заголовок (ручка, тип, свернуть, удалить), скрытый тип и поля типа. --}}
@php
    $definition = $field->getTypes()[$type];
    $blockPath = $path.'.'.$key;
    $invalid = $errors->has($blockPath.'.*') || collect($errors->keys())->contains(fn ($error) => str_starts_with($error, $blockPath.'.'));
    // Серая подпись рядом с типом блока: его заголовок («Преимущества — Почему мы»), чтобы на длинной странице блоки одного типа различались.
    $typeFields = $field->schema($type)->fields();
    $plainTexts = array_values(array_filter($typeFields, fn ($candidate) => $candidate::class === \Fastleo\Fields\Text::class));
    $hintField = collect($plainTexts)->first(fn ($candidate) => $candidate->name === 'title') ?? collect($plainTexts)->first(fn ($candidate) => $candidate->name === 'name') ?? ($plainTexts[0] ?? null);
    $hintName = $hintField === null ? null : $name.'['.$key.']['.$type.']['.$hintField->name.']';
@endphp
<div data-block data-block-kind="{{ $type }}" x-data="{ open: true, hint: '' }" @if ($hintName !== null) x-init="hint = $el.querySelector('[name=\'' + {{ \Illuminate\Support\Js::from($hintName) }} + '\']')?.value ?? ''" x-on:input="if ($event.target.name === {{ \Illuminate\Support\Js::from($hintName) }}) hint = $event.target.value" @endif class="overflow-hidden rounded-[10px] border border-line-strong bg-surface shadow-card">
    <div class="flex h-11 items-center gap-2 pr-1.5 pl-2">
        @if ($writable)
            <button type="button" data-block-handle class="cursor-grab touch-none text-ink-3 hover:text-ink" aria-label="{{ __('fastleo::resources.nested_move') }}">
                <x-fastleo::icon name="grip-vertical" class="size-4" />
            </button>
        @endif
        <span class="flex size-7 shrink-0 items-center justify-center rounded-[7px] bg-primary-soft text-primary-text"><x-fastleo::icon :name="$definition->getIcon()" class="size-4" /></span>
        <span @class(['min-w-0 truncate text-xs font-semibold', 'text-danger' => $invalid, 'text-ink-2' => ! $invalid]) title="{{ $definition->getLabel() }}" data-block-label>{{ $definition->getLabel() }}</span>
        <span class="min-w-0 flex-1 truncate text-xs font-normal text-ink-3" x-text="hint" data-block-hint></span>
        <button type="button" class="flex size-[30px] items-center justify-center rounded-[7px] text-ink-3 hover:bg-hover hover:text-ink" x-on:click="open = ! open" :aria-expanded="open" aria-label="{{ __('fastleo::resources.blocks_toggle') }}" data-block-toggle>
            <x-fastleo::icon name="chevron-down" class="size-4 transition-transform" x-bind:class="open || 'rotate-90'" />
        </button>
        @if ($writable)
            <button type="button" x-show="max === null || count < max" x-on:click="openMenu($el, $el.closest('[data-block]'))" x-bind:aria-expanded="menu && anchor === $el" class="flex size-[30px] items-center justify-center rounded-[7px] text-ink-3 hover:bg-hover hover:text-ink" aria-label="{{ __('fastleo::resources.blocks_insert') }}" x-bind:aria-label="{{ \Illuminate\Support\Js::from(__('fastleo::resources.blocks_insert').': '.$definition->getLabel()) }} + (hint ? ' — ' + hint : '')" title="{{ __('fastleo::resources.blocks_insert') }}" aria-haspopup="menu" data-block-insert>
                <x-fastleo::icon name="plus" class="size-4" />
            </button>
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
