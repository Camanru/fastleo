{{-- Вкладки: открыта первая, после ошибки проверки — первая вкладка с ошибкой, иначе вкладка из адреса (#ключ).
     Вкладка с таблицами связанных записей (Related) полей не содержит: её таблицы стоят вне формы (resources/related-tabs),
     а о выбранной вкладке они узнают из события fastleo-tab. --}}
@php
    $tabs = $layout->visibleTabs($record);
    // Форма в окне связанной таблицы: связанных таблиц нет, вкладки с ними не показываются.
    if ($framed ?? false) {
        $tabs = array_values(array_filter($tabs, fn ($tab) => ! $tab->isTable()));
    }
    $hasError = function ($tab) use ($errors): bool {
        if ($tab->isTable()) {
            return false;
        }

        foreach ((new \Fastleo\Support\FormSchema($tab->children(), inResource: true))->fields() as $field) {
            if ($errors->has($field->name) || $errors->has($field->name.'.*')) {
                return true;
            }
        }

        return false;
    };
    $active = 0;
    $failed = false;
    foreach ($tabs as $index => $tab) {
        if ($hasError($tab)) {
            $active = $index;
            $failed = true;
            break;
        }
    }
@endphp
<div data-tabs x-data="fastleoTabs({ keys: @js(array_map(fn ($tab) => $tab->key(), $tabs)), tables: @js(array_map(fn ($tab) => $tab->isTable(), $tabs)), active: {{ $active }}, failed: @js($failed), group: {{ spl_object_id($layout) }} })" @class(['flex flex-col', 'gap-4' => $cards ?? false, 'gap-5' => ! ($cards ?? false)])>
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
        @continue ($tab->isTable())
        <div role="tabpanel" @class(['flex flex-col', 'gap-4' => $cards ?? false, 'gap-5' => ! ($cards ?? false)]) x-show="tab === {{ $index }}" @if ($index !== $active) x-cloak @endif>
            @if ($cards ?? false)
                @include('fastleo::form.cards', ['items' => $tab->children(), 'record' => $record])
            @else
                @include('fastleo::form.items', ['items' => $tab->children(), 'record' => $record, 'card' => false, 'cards' => false])
            @endif
        </div>
    @endforeach
</div>
