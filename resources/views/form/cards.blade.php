{{-- Поля верхнего уровня формы в карточках: каждая Section — своя карточка, подряд идущие поля — одна общая,
     Tabs — полоса вкладок над карточками своей вкладки. Скрытые поля и пустые группы не выводятся. --}}
@php
    $groups = [];
    $visible = fn ($item): bool => $item instanceof \Fastleo\Fields\Field ? $item->isVisibleInForm($record) : $item->hasVisibleFields($record);

    foreach ($items as $item) {
        if (! $visible($item)) {
            continue;
        }

        $kind = match (true) {
            $item instanceof \Fastleo\Layout\Section => 'section',
            $item instanceof \Fastleo\Layout\Tabs => 'tabs',
            $item instanceof \Fastleo\Fields\Hidden => 'hidden',
            default => 'loose',
        };
        $last = count($groups) - 1;

        if (in_array($kind, ['loose', 'hidden'], true) && $last >= 0 && $groups[$last][0] === $kind) {
            $groups[$last][1][] = $item;
        } else {
            $groups[] = [$kind, [$item]];
        }
    }
@endphp
@foreach ($groups as [$kind, $group])
    @if ($kind === 'hidden')
        @include('fastleo::form.items', ['items' => $group, 'record' => $record, 'card' => false, 'cards' => false])
    @elseif ($kind === 'loose')
        <div class="fastleo-card flex min-w-0 flex-col gap-5 p-4 sm:p-5">
            @include('fastleo::form.items', ['items' => $group, 'record' => $record, 'card' => false, 'cards' => false])
        </div>
    @else
        @include('fastleo::form.items', ['items' => $group, 'record' => $record, 'card' => $kind === 'section', 'cards' => $kind === 'tabs'])
    @endif
@endforeach
