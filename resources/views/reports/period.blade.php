{{-- Период отчёта: готовые периоды ссылками и даты «с … по …»; выбор даты сразу применяет фильтр.
     $filter (Period), $value (PeriodValue), $form (id формы). --}}
@php
    $query = request()->query('filter', []);
    $query = is_array($query) ? $query : [];
    // Совпали два пресета (первый месяц квартала: «Месяц» и «Квартал») — подсвечивается один, первый.
    $active = collect($filter->presetRanges())->filter(fn (array $range): bool => $value->fromDate() === $range[0] && $value->toDate() === $range[1])->keys()->first();
    $link = fn (string $from, string $to): string => request()->fullUrlWithQuery(['filter' => [...$query, $filter->name => ['from' => $from, 'to' => $to]]]);
@endphp
<fieldset class="flex min-w-0 flex-col gap-1.5 text-sm sm:col-span-2" data-report-period="{{ $filter->name }}">
    <legend class="mb-1 text-ink-2">{{ $filter->getLabel() }}</legend>
    <div class="flex flex-wrap items-center gap-1" data-report-presets>
        @foreach ($filter->presetRanges() as $preset => [$from, $to])
            <a
                href="{{ $link($from, $to) }}"
                @class(['inline-flex h-8 items-center rounded-[8px] px-2.5 font-medium', 'bg-primary-soft text-primary-text' => $preset === $active, 'text-ink-2 hover:bg-hover hover:text-ink' => $preset !== $active])
                @if ($preset === $active) aria-current="true" @endif
            >{{ __('fastleo::reports.'.$preset) }}</a>
        @endforeach
    </div>
    <div class="flex items-center gap-2">
        <label class="min-w-0 flex-1">
            <span class="sr-only">{{ __('fastleo::reports.from') }}</span>
            <input type="date" name="filter[{{ $filter->name }}][from]" value="{{ $value->fromDate() }}" form="{{ $form }}" class="fastleo-input" title="{{ __('fastleo::reports.from') }}" x-on:change="$el.form.requestSubmit()">
        </label>
        <span class="text-ink-3">—</span>
        <label class="min-w-0 flex-1">
            <span class="sr-only">{{ __('fastleo::reports.to') }}</span>
            <input type="date" name="filter[{{ $filter->name }}][to]" value="{{ $value->toDate() }}" form="{{ $form }}" class="fastleo-input" title="{{ __('fastleo::reports.to') }}" x-on:change="$el.form.requestSubmit()">
        </label>
    </div>
</fieldset>
