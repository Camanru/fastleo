{{-- Значение поля в списке ($inList) или на просмотре: свой шаблон поля, listDisplay() или display(); пустое — прочерк. --}}
@php($inline = ($inList ?? false) && ! ($trashed ?? false) && isset($allows) && $field->isInlineEditable() ? \Fastleo\Support\InlineEdit::prepare($resource, $field, $record, $allows) : null)
@if ($field->getListView())
    @include($field->getListView(), ['record' => $record, 'field' => $field, 'value' => $record->getAttribute($field->name)])
@elseif (($inList ?? false) && ! ($trashed ?? false) && $field instanceof \Fastleo\Fields\Checkbox && isset($allows) && $field->quickToggleFor($record) && $allows('update', $record))
    @include('fastleo::resources.quick-toggle')
@elseif ($inline !== null)
    @include('fastleo::resources.inline-edit')
@else
    @php($shown = $field->shownRecord($record))
    @php($badges = $field instanceof \Fastleo\Fields\Select && $field->hasBadges() ? $field->badgeItems($shown) : [])
    @php($display = ($inList ?? false) ? $field->listDisplay($shown) : $field->display($shown))
    @if ($badges !== [])
        <span class="inline-flex flex-wrap gap-1">
            @foreach ($badges as $badge)
                <x-fastleo::badge :tone="$badge['tone']">{{ $badge['label'] }}</x-fastleo::badge>
            @endforeach
        </span>
    @elseif ($display === null || $display === '')
        <span class="text-ink-3">—</span>
    @else
        {{ $display }}
    @endif
@endif
