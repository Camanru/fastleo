{{-- Панель «История изменений» в боковой колонке карточки записи ($resource, $record): последние записи журнала —
     кто, когда и что изменил. Полная история — по ссылке. Нужен журнал (activity) и право смотреть запись. --}}
@php
    $slug = $resource::slug();
    $entries = \Fastleo\Activity\Activity::query()->where('resource', $slug)->where('subject_id', $record->getKey())->latest('id')->limit(10)->get();
    $labels = [];

    foreach ($resource->schema()->fields() as $field) {
        if (! $field->isHidden()) {
            $labels[$field->name] = $field->getLabel();
        }
    }
@endphp
<x-fastleo::card :title="__('fastleo::activity.panel_title')" data-history-panel>
    <x-slot:action>
        <a href="{{ route('fastleo.resources.history', [$slug, $record->getKey()]) }}" class="text-xs font-medium text-primary-text hover:underline">{{ __('fastleo::activity.panel_all') }}</a>
    </x-slot:action>
    @forelse ($entries as $entry)
        @php
            $changes = collect($entry->changes ?? [])->reject(fn ($change) => ! ($change['hidden'] ?? false) && \Fastleo\Activity\Activity::isBlank($change['old'] ?? null) && \Fastleo\Activity\Activity::isBlank($change['new'] ?? null));
        @endphp
        <div class="flex flex-col gap-1 border-t border-line pt-2.5 text-sm first:border-t-0 first:pt-0" data-history-entry="{{ $entry->event }}">
            <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span class="text-xs text-ink-2 tabular-nums">{{ $entry->created_at?->timezone(\Fastleo\Fastleo::timezone())->format('d.m.Y H:i') }}</span>
                <span class="font-semibold">{{ $entry->user_name ?? __('fastleo::activity.author_unknown') }}</span>
                <x-fastleo::badge :tone="match ($entry->event) { 'created' => 'success', 'updated', 'reverted' => 'info', 'deleted', 'force_deleted' => 'danger', default => 'neutral' }">{{ __('fastleo::activity.events.'.$entry->event) }}</x-fastleo::badge>
            </p>
            @if ($entry->event !== 'import')
                @foreach ($changes->take(4) as $attribute => $change)
                    <p class="min-w-0 text-xs break-words text-ink-2">
                        {{ $labels[$attribute] ?? $attribute }}:
                        @if (($change['hidden'] ?? false) || ! isset($labels[$attribute]))
                            {{ __('fastleo::activity.hidden_value') }}
                        @else
                            <span class="text-ink">{{ \Illuminate\Support\Str::limit(\Fastleo\Activity\Activity::display($change['new'] ?? null), 80) }}</span>
                        @endif
                    </p>
                @endforeach
                @if ($changes->count() > 4)
                    <p class="text-xs text-ink-3">{{ __('fastleo::activity.panel_more', ['count' => $changes->count() - 4]) }}</p>
                @endif
            @endif
        </div>
    @empty
        <p class="text-sm text-ink-2">{{ __('fastleo::activity.panel_empty') }}</p>
    @endforelse
</x-fastleo::card>
