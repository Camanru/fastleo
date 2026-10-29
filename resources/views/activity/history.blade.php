{{-- История одной записи. $resource, $record (null, если запись удалена), $recordId, $entries, $resources. --}}
@php($name = $entries->first()?->subject_label ?? '#'.$recordId)
@php($backUrl = $record !== null ? route('fastleo.resources.show', [$resource::slug(), $recordId]) : route('fastleo.resources.index', $resource::slug()))
<x-fastleo::layouts.app
    :title="__('fastleo::activity.history_title', ['name' => $resource::singular()])"
    :description="$name.($record === null ? ' · '.__('fastleo::activity.deleted_record') : '')"
    :back="['url' => $backUrl, 'label' => $record !== null ? __('fastleo::activity.back') : $resource::label()]"
>
    <x-fastleo::flash />

    @include('fastleo::activity.entries', ['showSubject' => false])
</x-fastleo::layouts.app>
