{{-- Сетка: на телефоне одна колонка, с планшета — заданное число. Полные имена классов — чтобы Tailwind их собрал. --}}
@php
    $columns = [1 => 'md:grid-cols-1', 2 => 'md:grid-cols-2', 3 => 'md:grid-cols-3', 4 => 'md:grid-cols-4', 5 => 'md:grid-cols-5', 6 => 'md:grid-cols-6', 7 => 'md:grid-cols-7', 8 => 'md:grid-cols-8', 9 => 'md:grid-cols-9', 10 => 'md:grid-cols-10', 11 => 'md:grid-cols-11', 12 => 'md:grid-cols-12'];
    $spans = [1 => 'md:col-span-1', 2 => 'md:col-span-2', 3 => 'md:col-span-3', 4 => 'md:col-span-4', 5 => 'md:col-span-5', 6 => 'md:col-span-6', 7 => 'md:col-span-7', 8 => 'md:col-span-8', 9 => 'md:col-span-9', 10 => 'md:col-span-10', 11 => 'md:col-span-11', 12 => 'md:col-span-12'];
@endphp
<div class="grid grid-cols-1 gap-5 {{ $columns[$layout->columns] }}">
    @foreach ($layout->children() as $child)
        @php($visible = $child instanceof \Fastleo\Fields\Field ? $child->isVisibleInForm($record) : $child->hasVisibleFields($record))
        @if ($visible)
            @php($span = $child instanceof \Fastleo\Fields\Field ? $child->getColumnSpan() : null)
            <div class="min-w-0 {{ $span !== null ? $spans[min($span, $layout->columns)] : '' }}">
                @include('fastleo::form.items', ['items' => [$child], 'record' => $record])
            </div>
        @endif
    @endforeach
</div>
