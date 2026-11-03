{{-- Значение поля в списке ($inList) или на просмотре: свой шаблон поля, listDisplay() или display(); пустое — прочерк.
     $linked (по умолчанию да) — связь BelongsTo в списке ссылкой на связанную запись; в первой ячейке ссылка уже есть. --}}
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
    @php($link = ($inList ?? false) && ($linked ?? true) && ! ($trashed ?? false) && $field instanceof \Fastleo\Fields\BelongsTo && ! $field->isInlineEditable() && $record instanceof \Illuminate\Database\Eloquent\Model && is_string($display) && $display !== '' ? $field->linkUrl($record) : null)
    @if ($badges !== [])
        <span class="inline-flex flex-wrap gap-1">
            @foreach ($badges as $badge)
                <x-fastleo::badge :tone="$badge['tone']">{{ $badge['label'] }}</x-fastleo::badge>
            @endforeach
        </span>
    @elseif ($display === null || $display === '')
        <span class="text-ink-3">—</span>
    @elseif ($link !== null)
        <a href="{{ $link }}" class="hover:text-primary-text hover:underline" data-related-link{!! ($modalLink = \Fastleo\Resource::viewModalUrl($link)) ? ' data-record-view="'.e($modalLink).'"' : '' !!}>{{ $display }}</a>
    @else
        {{ $display }}
    @endif
@endif
