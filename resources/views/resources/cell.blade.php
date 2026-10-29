{{-- Значение поля в списке ($inList) или на просмотре: свой шаблон поля, listDisplay() или display(); пустое — прочерк. --}}
@if ($field->getListView())
    @include($field->getListView(), ['record' => $record, 'field' => $field, 'value' => $record->getAttribute($field->name)])
@else
    @php($shown = $field->shownRecord($record))
    @php($display = ($inList ?? false) ? $field->listDisplay($shown) : $field->display($shown))
    @if ($display === null || $display === '')
        <span class="text-ink-3">—</span>
    @else
        {{ $display }}
    @endif
@endif
