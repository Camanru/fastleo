{{-- Общий журнал изменений. $entries, $resources, $authors, $filters. --}}
@php($keepDays = max(1, config()->integer('fastleo.activity.keep_days', 30)))
<x-fastleo::layouts.app :title="__('fastleo::activity.title')" :description="__('fastleo::activity.help', ['days' => trans_choice('fastleo::activity.days', $keepDays, ['count' => $keepDays])])">
    <x-fastleo::flash />


    <form method="GET" action="{{ route('fastleo.activity.index') }}" class="flex flex-wrap items-end gap-3" data-activity-filters>
        <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
            {{ __('fastleo::activity.filter_resource') }}
            <select name="resource" class="fastleo-input min-w-48">
                <option value="">{{ __('fastleo::activity.filter_all') }}</option>
                @foreach ($resources as $slug => $resource)
                    <option value="{{ $slug }}" @selected($filters['resource'] === $slug)>{{ $resource::label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
            {{ __('fastleo::activity.filter_user') }}
            <select name="user" class="fastleo-input min-w-48">
                <option value="">{{ __('fastleo::activity.filter_all') }}</option>
                @foreach ($authors as $id => $name)
                    <option value="{{ $id }}" @selected($filters['user'] === (string) $id)>{{ $name ?? __('fastleo::activity.author_unknown') }}</option>
                @endforeach
            </select>
        </label>
        <x-fastleo::button type="submit" variant="primary">{{ __('fastleo::activity.filter_apply') }}</x-fastleo::button>
    </form>

    @include('fastleo::activity.entries', ['showSubject' => true])
</x-fastleo::layouts.app>
