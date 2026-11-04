{{-- MorphTo: список разделов-типов и рядом выбор записи выбранного раздела (поиск по серверу). Смена раздела сбрасывает запись.
     Значения — subject[type] (адрес раздела) и subject[id] (ключ записи). Сохранённое значение, которое выбрать нельзя
     (раздел убран из types(), закрыт или класса нет), выводится текстом и не отправляется. --}}
@php
    $locked = $field->isLocked($record);
    $writable = $field->isWritable();
    $chosen = $field->selection(old($path, $value));
    $types = $field->offeredTypes();
    $selected = $field->selectedOptions($chosen['type'], $chosen['id']);
    $combobox = [
        'multiple' => false,
        'options' => [],
        'selected' => $selected,
        'url' => route('fastleo.resources.options', [$resource::slug(), $field->name]),
        'remote' => true,
        'depends' => $name.'[type]',
        'record' => null,
    ];
    $invalid = $errors->has($path) || $errors->has($path.'.*');
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    @if ($locked)
        <div class="fastleo-input bg-sunken text-ink-2" id="{{ $id }}">{{ $field->lockedText($record) }}</div>
    @elseif (! $writable)
        <div class="fastleo-input bg-sunken text-ink-2" id="{{ $id }}">
            {{ collect($types)->firstWhere('value', $chosen['type'])['label'] ?? '—' }}{{ $selected !== [] ? ': '.$selected[0]['label'] : '' }}
        </div>
    @else
        <div class="flex flex-col gap-2 sm:flex-row" data-morph-to="{{ $path }}">
            <select
                id="{{ $id }}"
                name="{{ $name }}[type]"
                @disabled($field->isDisabled())
                @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
                @class(['fastleo-input sm:w-56', 'fastleo-input-invalid' => $invalid])
            >
                <option value="">—</option>
                @foreach ($types as $type)
                    <option value="{{ $type['value'] }}" @selected($chosen['type'] === $type['value'])>{{ $type['label'] }}</option>
                @endforeach
            </select>
            <div class="min-w-0 flex-1">
                @include('fastleo::fields.combobox-control', [
                    'combobox' => $combobox,
                    'multiple' => false,
                    'required' => $field->isRequired(),
                    'placeholder' => $field->getPlaceholder() ?? __('fastleo::resources.combobox_placeholder'),
                    'name' => $name.'[id]',
                    'id' => $id.'-id',
                    'path' => $path.'.id',
                ])
            </div>
        </div>
    @endif
</x-fastleo::field>
