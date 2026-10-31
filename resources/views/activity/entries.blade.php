{{-- Список записей журнала. $entries (постранично), $resources (адрес → ресурс), $showSubject. --}}
@php
    $timezone = \Fastleo\Fastleo::timezone();
    $days = $entries->getCollection()->groupBy(fn ($entry) => $entry->created_at?->timezone($timezone)->toDateString());
    $dayLabel = function (string $day) use ($timezone): string {
        $date = \Illuminate\Support\Carbon::parse($day, $timezone);

        return match (true) {
            $date->isToday() => __('fastleo::activity.today'),
            $date->isYesterday() => __('fastleo::activity.yesterday'),
            default => $date->format('d.m.Y'),
        };
    };
@endphp
@forelse ($days as $day => $dayEntries)
<section class="flex flex-col gap-2">
<h2 class="text-xs font-semibold tracking-[.04em] text-ink-3 uppercase">{{ $dayLabel((string) $day) }}</h2>
<ul class="flex flex-col divide-y divide-line rounded-card border border-line bg-surface shadow-card" data-activity-list>
    @foreach ($dayEntries as $entry)
        @php
            $resource = $resources[$entry->resource] ?? null;
            $restricted = in_array($entry->id, $hiddenEntries ?? [], true);
            $subject = ($entryRecords ?? [])[$entry->id] ?? null;
            $labels = [];
            if ($resource !== null) {
                foreach ($resource->schema()->fields() as $field) {
                    // Записи нет (удалена насовсем): поле со скрытием по условию на запись не показывается — проверить не на чем.
                    if ($subject === null ? ! $field->hasHiddenCondition() && ! $field->isHidden() : ! $field->isHidden($subject)) {
                        $labels[$field->name] = $field->getLabel();
                    }
                }
            }
            $canRevert = ! $restricted && $resource !== null && $entry->isRevertable() && match (true) {
                in_array($entry->event, ['updated', 'reverted'], true) => \Fastleo\Support\Authorization::allows('update', $resource),
                $entry->snapshot !== null => \Fastleo\Support\Authorization::allows('create', $resource),
                default => \Fastleo\Support\Authorization::allows('restore', $resource),
            };
            $revertsValues = in_array($entry->event, ['updated', 'reverted'], true);
        @endphp
        @php
            $shownChanges = collect($entry->changes ?? [])->reject(fn ($change) => ! ($change['hidden'] ?? false) && \Fastleo\Activity\Activity::isBlank($change['old'] ?? null) && \Fastleo\Activity\Activity::isBlank($change['new'] ?? null));
            $hasDiff = ! $restricted && $entry->event !== 'import' && $shownChanges->isNotEmpty();
            $author = $entry->user_name ?? __('fastleo::activity.author_unknown');
            $initials = \Illuminate\Support\Str::of($author)->explode(' ')->filter()->take(2)->map(fn ($word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))->implode('');
        @endphp
        {{-- В общем журнале раскрыта только первая запись страницы, в истории записи — все. --}}
        <li class="flex flex-col text-sm" data-activity-entry="{{ $entry->event }}" x-data="{ open: @js(! $showSubject || ($loop->parent->first && $loop->first)) }">
            <div class="flex items-start gap-3 px-4 py-3">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-sunken text-[11px] font-semibold text-ink-2" aria-hidden="true">{{ $initials ?: '?' }}</span>
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <p class="min-w-0 break-words">
                        <span class="font-semibold">{{ $author }}</span>
                        @if ($showSubject)
                            <span class="text-ink-2">· {{ $resource?->label() ?? $entry->resource }}</span>
                            @if ($entry->subject_id !== null)
                                @if ($restricted)
                                    <span class="text-ink-2" data-activity-restricted>· {{ __('fastleo::activity.restricted') }}</span>
                                @else
                                    · <a class="font-[550] text-primary-text hover:underline" href="{{ route('fastleo.resources.history', [$entry->resource, $entry->subject_id]) }}">{{ $entry->subject_label ?? '#'.$entry->subject_id }}</a>
                                @endif
                            @endif
                        @endif
                    </p>
                    <p class="text-xs text-ink-2 tabular-nums">
                        {{ $entry->created_at?->timezone(\Fastleo\Fastleo::timezone())->format('H:i') }}@if ($hasDiff) · {{ __('fastleo::activity.changed_fields', ['count' => $shownChanges->count()]) }}@endif
                    </p>
                    @if (! $restricted && $entry->event === 'import' && $entry->changes)
                        <p class="text-ink">
                            @foreach ($entry->changes as $key => $change)
                                {{ __('fastleo::activity.import_'.$key) }}: {{ \Fastleo\Activity\Activity::display($change['new'] ?? null) }}@if (! $loop->last), @endif
                            @endforeach
                        </p>
                    @endif
                </div>
                <x-fastleo::badge :tone="match ($entry->event) { 'created' => 'success', 'updated', 'reverted' => 'info', 'deleted', 'force_deleted' => 'danger', default => 'neutral' }">{{ __('fastleo::activity.events.'.$entry->event) }}</x-fastleo::badge>
                @if ($hasDiff)
                    <button type="button" class="flex size-7 shrink-0 items-center justify-center rounded-[7px] text-ink-2 hover:bg-hover" :class="open && 'bg-sunken'" @click="open = ! open" :aria-expanded="open" aria-label="{{ __('fastleo::activity.changed_fields', ['count' => $shownChanges->count()]) }}">
                        <x-fastleo::icon name="chevron-down" class="size-4 transition-transform" ::class="open && 'rotate-180'" />
                    </button>
                @endif
                @if ($canRevert && ! $hasDiff)
                    <x-fastleo::action-form :action="route('fastleo.activity.revert', $entry->id)" :confirm="__($revertsValues ? 'fastleo::activity.revert_confirm' : 'fastleo::activity.revert_deleted_confirm')" :button="__($revertsValues ? 'fastleo::activity.revert' : 'fastleo::activity.revert_deleted')" :danger="false">
                        <x-fastleo::button type="submit" size="sm" icon="undo-2" data-activity-revert>{{ __($revertsValues ? 'fastleo::activity.revert' : 'fastleo::activity.revert_deleted') }}</x-fastleo::button>
                    </x-fastleo::action-form>
                @endif
            </div>

            @if ($hasDiff)
                <div x-show="open" @if (! (! $showSubject || ($loop->parent->first && $loop->first))) x-cloak @endif class="mx-4 mb-3.5 overflow-hidden rounded-[10px] border border-line text-sm sm:ml-[60px]">
                    <div class="hidden grid-cols-[160px_1fr_1fr] gap-3 border-b border-line bg-surface-2 px-3 py-2 text-xs font-[550] text-ink-2 sm:grid">
                        <span>{{ __('fastleo::activity.diff_field') }}</span>
                        <span>{{ __('fastleo::activity.diff_old') }}</span>
                        <span>{{ __('fastleo::activity.diff_new') }}</span>
                    </div>
                    @foreach ($shownChanges as $attribute => $change)
                        <div class="grid grid-cols-1 gap-1 border-b border-line px-3 py-2 last:border-b-0 sm:grid-cols-[160px_1fr_1fr] sm:gap-3">
                            <span class="text-ink-2">{{ $labels[$attribute] ?? $attribute }}</span>
                            @if (($change['hidden'] ?? false) || ! isset($labels[$attribute]))
                                <span class="text-ink-2 sm:col-span-2">{{ __('fastleo::activity.hidden_value') }}</span>
                            @elseif ($entry->event === 'created' || \Fastleo\Activity\Activity::isBlank($change['old'] ?? null))
                                <span class="text-ink-3">{{ $entry->event === 'created' ? '' : __('fastleo::activity.added') }}</span>
                                <span class="min-w-0 break-words"><span class="rounded bg-success-soft px-1 text-success">{{ \Fastleo\Activity\Activity::display($change['new'] ?? null) }}</span></span>
                            @else
                                <span class="min-w-0 break-words"><span class="rounded bg-danger-soft px-1 text-danger line-through">{{ \Fastleo\Activity\Activity::display($change['old'] ?? null) }}</span></span>
                                <span class="min-w-0 break-words"><span class="rounded bg-success-soft px-1 text-success">{{ \Fastleo\Activity\Activity::display($change['new'] ?? null) }}</span></span>
                            @endif
                        </div>
                    @endforeach
                    @if ($canRevert)
                        <div class="flex items-center justify-end border-t border-line bg-surface-2 px-3 py-2">
                            <x-fastleo::action-form :action="route('fastleo.activity.revert', $entry->id)" :confirm="__($revertsValues ? 'fastleo::activity.revert_confirm' : 'fastleo::activity.revert_deleted_confirm')" :button="__($revertsValues ? 'fastleo::activity.revert' : 'fastleo::activity.revert_deleted')" :danger="false">
                                <x-fastleo::button type="submit" size="sm" icon="undo-2" data-activity-revert>{{ __($revertsValues ? 'fastleo::activity.revert' : 'fastleo::activity.revert_deleted') }}</x-fastleo::button>
                            </x-fastleo::action-form>
                        </div>
                    @endif
                </div>
            @endif
        </li>
    @endforeach
</ul>
</section>
@empty
<ul class="flex flex-col divide-y divide-line rounded-card border border-line bg-surface shadow-card" data-activity-list>
    <li class="p-4 text-sm text-ink-2">{{ __('fastleo::activity.empty') }}</li>
</ul>
@endforelse

@if ($entries->hasPages())
    <div class="flex items-center justify-between gap-3 text-sm">
        @if ($entries->onFirstPage())
            <span></span>
        @else
            <x-fastleo::button icon="chevron-left" :href="$entries->previousPageUrl()">{{ __('fastleo::resources.previous') }}</x-fastleo::button>
        @endif
        @if ($entries->hasMorePages())
            <x-fastleo::button icon="chevron-right" :href="$entries->nextPageUrl()">{{ __('fastleo::resources.next') }}</x-fastleo::button>
        @endif
    </div>
@endif
