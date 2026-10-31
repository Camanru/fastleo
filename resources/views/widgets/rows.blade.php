{{-- Таблица со своими строками (Rows): $columns, $rows (ячейки text/number), $footer. Значения экранируются. --}}
@if ($rows === [])
    <p class="text-sm text-ink-2">{{ __('fastleo::dashboard.table_empty') }}</p>
@else
    <div class="relative -mx-5 overflow-x-auto">
        <table class="w-full border-separate border-spacing-0 text-left text-base">
            <thead>
                <tr class="[&>th]:h-9 [&>th]:border-b [&>th]:border-line [&>th]:px-5 [&>th]:text-xs [&>th]:font-[550] [&>th]:whitespace-nowrap [&>th]:text-ink-2">
                    @foreach ($columns as $column)
                        <th scope="col" @class(['!text-right' => $loop->index > 0 && ($rows[0][$loop->index]['number'] ?? false)])>{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="[&>td]:border-b [&>td]:border-line [&>td]:px-5 [&>td]:py-2 [&:last-child>td]:border-b-0">
                        @foreach ($row as $cell)
                            <td @class(['text-right tabular-nums' => $cell['number']])>{{ $cell['text'] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($footer)
                <tfoot>
                    <tr class="font-semibold [&>td]:border-t [&>td]:border-line [&>td]:bg-surface-2 [&>td]:px-5 [&>td]:py-2">
                        @foreach ($footer as $cell)
                            <td @class(['text-right tabular-nums' => $cell['number']])>{{ $cell['text'] }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
@endif
