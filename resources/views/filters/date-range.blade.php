{{-- Фильтр по датам «с … по …». --}}
<fieldset class="flex min-w-0 flex-col gap-1 text-sm sm:col-span-2">
    <legend class="mb-1 text-ink-2">{{ $filter->getLabel() }}</legend>
    <div class="flex items-center gap-2">
        <label class="min-w-0 flex-1">
            <span class="sr-only">{{ __('fastleo::resources.filter_from') }}</span>
            <input type="date" name="filter[{{ $filter->name }}][from]" value="{{ $value['from'] ?? '' }}" form="{{ $form }}" class="fastleo-input" title="{{ __('fastleo::resources.filter_from') }}" @if ($auto ?? true)x-on:change="$el.form.requestSubmit()"@endif>
        </label>
        <span class="text-ink-3">—</span>
        <label class="min-w-0 flex-1">
            <span class="sr-only">{{ __('fastleo::resources.filter_to') }}</span>
            <input type="date" name="filter[{{ $filter->name }}][to]" value="{{ $value['to'] ?? '' }}" form="{{ $form }}" class="fastleo-input" title="{{ __('fastleo::resources.filter_to') }}" @if ($auto ?? true)x-on:change="$el.form.requestSubmit()"@endif>
        </label>
    </div>
</fieldset>
