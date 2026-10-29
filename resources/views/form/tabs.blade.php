{{-- Вкладки: открыта первая, а после ошибки проверки — первая вкладка с ошибкой. --}}
@php
    $tabs = $layout->visibleTabs($record);
    $hasError = function ($tab) use ($errors): bool {
        foreach ((new \Fastleo\Support\FormSchema($tab->children()))->fields() as $field) {
            if ($errors->has($field->name) || $errors->has($field->name.'.*')) {
                return true;
            }
        }

        return false;
    };
    $active = 0;
    foreach ($tabs as $index => $tab) {
        if ($hasError($tab)) {
            $active = $index;
            break;
        }
    }
@endphp
<div x-data="{ tab: {{ $active }} }" @class(['flex flex-col', 'gap-4' => $cards ?? false, 'gap-5' => ! ($cards ?? false)])>
    <div @class(['overflow-x-auto overflow-y-hidden border-b border-line', '-mx-4 px-4 sm:-mx-5 sm:px-5' => ! ($cards ?? false)]) role="tablist">
        <div class="flex gap-1">
            @foreach ($tabs as $index => $tab)
                <button
                    type="button"
                    role="tab"
                    class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-base font-medium whitespace-nowrap"
                    :class="tab === {{ $index }} ? 'border-primary font-semibold text-ink' : 'border-transparent text-ink-2 hover:text-ink'"
                    :aria-selected="tab === {{ $index }}"
                    @click="tab = {{ $index }}"
                >
                    {{ $tab->label }}
                    @if ($hasError($tab))
                        <span class="size-2 rounded-full bg-danger-solid" aria-hidden="true"></span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>
    @foreach ($tabs as $index => $tab)
        <div role="tabpanel" @class(['flex flex-col', 'gap-4' => $cards ?? false, 'gap-5' => ! ($cards ?? false)]) x-show="tab === {{ $index }}" @if ($index !== $active) x-cloak @endif>
            @if ($cards ?? false)
                @include('fastleo::form.cards', ['items' => $tab->children(), 'record' => $record])
            @else
                @include('fastleo::form.items', ['items' => $tab->children(), 'record' => $record, 'card' => false, 'cards' => false])
            @endif
        </div>
    @endforeach
</div>
