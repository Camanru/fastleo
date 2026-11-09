{{-- Фильтр-список: «Все» и варианты; выбор сразу применяет фильтр (если раздел не требует кнопки «Фильтровать»). --}}
<label class="flex min-w-0 flex-col gap-1 text-sm">
    <span class="text-ink-2">{{ $filter->getLabel() }}</span>
    <select name="filter[{{ $filter->name }}]" form="{{ $form }}" class="fastleo-input" @if ($auto ?? true)x-on:change="$el.form.requestSubmit()"@endif>
        <option value="">{{ __('fastleo::resources.filter_all') }}</option>
        @foreach ($filter->getOptions() as $option => $label)
            <option value="{{ $option }}" @selected($value === (string) $option)>{{ $label }}</option>
        @endforeach
    </select>
</label>
