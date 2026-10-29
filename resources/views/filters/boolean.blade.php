{{-- Фильтр «Все / Да / Нет». --}}
<label class="flex min-w-0 flex-col gap-1 text-sm">
    <span class="text-ink-2">{{ $filter->getLabel() }}</span>
    <select name="filter[{{ $filter->name }}]" form="{{ $form }}" class="fastleo-input" x-on:change="$el.form.requestSubmit()">
        <option value="">{{ __('fastleo::resources.filter_all') }}</option>
        <option value="1" @selected($value === true)>{{ __('fastleo::resources.yes') }}</option>
        <option value="0" @selected($value === false)>{{ __('fastleo::resources.no') }}</option>
    </select>
</label>
