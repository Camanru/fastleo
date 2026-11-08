{{-- Select: один вариант — выпадающий список; несколько (multiple()) — плашки: видны все варианты, выбранные залиты цветом
     с галочкой. Для множественного пустое скрытое поле отправляет «ничего не выбрано».
     С dependsOn() варианты подгружаются с сервера при смене поля-зависимости. --}}
@php
    $old = old($path, $value);
    $selected = array_map('strval', array_filter(is_array($old) ? $old : ($old === null || $old === '' ? [] : [$old]), 'is_scalar'));
    $depends = $field->getDependsOn();
    $dependency = $depends === null ? null : $resource->controllingValue($depends, $record, session()->hasOldInput() ? session()->getOldInput() : null);
    $options = $field->getOptions($dependency);
    $writable = ! $field->isDisabled() && ! $field->isReadonly();
    $invalid = $errors->has($path) || $errors->has($path.'.*');
    $dependent = $depends !== null && $field->isWritable()
        ? 'fastleoDependentSelect({ url: '.Illuminate\Support\Js::from(route('fastleo.resources.options', [$resource::slug(), $field->name])).', depends: '.Illuminate\Support\Js::from($depends).' })'
        : null;
@endphp
@if ($field->isMultiple())
    @php
        // Сохранённые значения вне вариантов остаются плашками, пока админ их не снимет.
        $chips = $options;
        foreach ($field->savedValues($record) as $savedValue) {
            if (! array_key_exists($savedValue, $chips)) {
                $chips[$savedValue] = $savedValue;
            }
        }
    @endphp
    <x-fastleo::field :field="$field" :id="$id" :path="$path" :inline="true">
        <fieldset
            @if ($dependent !== null) x-data="{{ $dependent }}" @endif
            @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            class="flex flex-col gap-2"
        >
            <legend class="mb-1 text-sm font-medium text-ink-2">
                {{ $field->getLabel() }}
                @if ($field->isRequired())<span class="text-danger" aria-hidden="true">*</span>@endif
            </legend>
            @if ($writable)
                <input type="hidden" name="{{ $name }}" value="">
            @endif
            {{-- Без required у галочек: браузер потребовал бы отметить каждую, обязательность проверяет сервер.
                 id поля — у первой галочки, а без вариантов — у списка, чтобы подсказка и ошибка ссылались на элемент. --}}
            <div @if ($chips === []) id="{{ $id }}" @endif class="flex flex-wrap gap-2" data-chips>
                @foreach ($chips as $optionValue => $optionLabel)
                    <label @class([
                        'group/chip inline-flex items-center gap-1.5 rounded-full border border-line-strong bg-surface px-3 py-1.5 text-sm text-ink-2 transition-colors has-checked:border-primary has-checked:bg-primary-soft has-checked:font-medium has-checked:text-primary-text has-focus-visible:ring-2 has-focus-visible:ring-primary/40',
                        'cursor-pointer hover:bg-surface-2' => $writable,
                        'opacity-60' => ! $writable,
                    ])>
                        <input
                            type="checkbox"
                            @if ($loop->first) id="{{ $id }}" @endif
                            @if ($writable) name="{{ $name }}[]" @endif
                            value="{{ $optionValue }}"
                            class="sr-only"
                            @checked(in_array((string) $optionValue, $selected, true))
                            @disabled(! $writable)
                        >
                        <x-fastleo::icon name="check" class="hidden size-3.5 shrink-0 group-has-checked/chip:block" />
                        <span>{{ $optionLabel }}</span>
                    </label>
                @endforeach
            </div>
            @if ($dependent !== null)
                {{-- Плашка для вариантов, пришедших с сервера: классы должны быть в шаблоне, сборка CSS не смотрит в JS. --}}
                <template data-chip>
                    <label class="group/chip inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line-strong bg-surface px-3 py-1.5 text-sm text-ink-2 transition-colors hover:bg-surface-2 has-checked:border-primary has-checked:bg-primary-soft has-checked:font-medium has-checked:text-primary-text has-focus-visible:ring-2 has-focus-visible:ring-primary/40">
                        <input type="checkbox" name="{{ $name }}[]" value="" class="sr-only">
                        <x-fastleo::icon name="check" class="hidden size-3.5 shrink-0 group-has-checked/chip:block" />
                        <span data-chip-label></span>
                    </label>
                </template>
            @endif
        </fieldset>
    </x-fastleo::field>
@else
    <x-fastleo::field :field="$field" :id="$id" :path="$path">
        <select
            id="{{ $id }}"
            @if ($dependent !== null) x-data="{{ $dependent }}" @endif
            name="{{ $name }}"
            @required($field->isRequired())
            @disabled(! $writable)
            @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            @class(['fastleo-input', 'fastleo-input-invalid' => $invalid])
        >
            <option value="">{{ $field->getPlaceholder() ?? '—' }}</option>
            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected(in_array((string) $optionValue, $selected, true))>{{ $optionLabel }}</option>
            @endforeach
            @foreach ($field->savedValues($record) as $savedValue)
                @unless (array_key_exists($savedValue, $options))
                    <option value="{{ $savedValue }}" @selected(in_array($savedValue, $selected, true))>{{ $savedValue }}</option>
                @endunless
            @endforeach
        </select>
    </x-fastleo::field>
@endif
