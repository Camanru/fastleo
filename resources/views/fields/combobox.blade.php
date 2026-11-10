{{-- Список с поиском для связей (BelongsTo — одна запись, BelongsToMany — несколько).
     Варианты — в странице или, если их много, поиском по серверу. Значения — в скрытых полях.
     С dependsOn() при смене поля-зависимости выбор сбрасывается, варианты запрашиваются заново.
     Внутри блока HasMany/HasOne (BelongsTo) варианты всегда ищутся по серверу. --}}
@php
    $old = old($path, $value);
    $keys = array_values(array_map('strval', array_filter(is_array($old) ? $old : [$old], fn ($key) => is_scalar($key) && $key !== '')));
    $writable = $field->isWritable();
    $depends = $field->getDependsOn();
    $dependency = $depends === null ? null : $resource->controllingValue($depends, $record, session()->hasOldInput() ? session()->getOldInput() : null);
    // В блоке HasMany/HasOne варианты всегда ищутся по серверу: иначе список вариантов повторился бы в каждом блоке и в шаблоне.
    $remote = $writable && ($field->nestedParent() !== null || $field->isRemote($dependency));
    $combobox = [
        'multiple' => $field->isMultiple(),
        'options' => $writable && ! $remote ? $field->preloadedOptions($dependency) : [],
        'selected' => $field->optionsFor($keys, $dependency, $field->savedKeys($record)),
        'url' => $remote || ($writable && $depends !== null) ? route('fastleo.resources.options', [$resource::slug(), $field->optionsPath()]) : null,
        'remote' => $remote,
        'depends' => $depends,
        'record' => $depends !== null && $record?->exists ? (string) $record->getKey() : null,
    ];
    $invalid = $errors->has($path) || $errors->has($path.'.*');
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    @if (! $writable)
        <div class="fastleo-input bg-sunken text-ink-2" id="{{ $id }}">
            {{ collect($combobox['selected'])->pluck('label')->join(', ') ?: '—' }}
        </div>
    @else
        @include('fastleo::fields.combobox-control', [
            'combobox' => $combobox,
            'multiple' => $field->isMultiple(),
            'required' => $field->isRequired(),
            'placeholder' => $field->getPlaceholder() ?? __('fastleo::resources.combobox_placeholder'),
        ])
        @if ($field instanceof \Fastleo\Fields\BelongsToMany)
            {{-- Связи при открытии формы: отвязываются только они, связь другого пользователя устаревшая форма не стирает. --}}
            <input type="hidden" name="{{ $field->seenInputName() }}" value="{{ $field->seenInputValue(implode(',', $field->savedKeys($record))) }}">
        @endif
    @endif
</x-fastleo::field>
