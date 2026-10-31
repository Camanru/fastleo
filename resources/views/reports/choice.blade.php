{{-- Список значений отчёта: «Все» и варианты, выбор сразу применяет фильтр. $filter (Choice), $value (ключ или null), $form. --}}
<label class="flex min-w-0 flex-col gap-1 text-sm">
    <span class="text-ink-2">{{ $filter->getLabel() }}</span>
    <select name="filter[{{ $filter->name }}]" form="{{ $form }}" class="fastleo-input" x-on:change="$el.form.requestSubmit()">
        <option value="">{{ __('fastleo::reports.all') }}</option>
        @foreach ($filter->getOptions() as $option => $label)
            <option value="{{ $option }}" @selected($value === (string) $option)>{{ $label }}</option>
        @endforeach
    </select>
</label>
