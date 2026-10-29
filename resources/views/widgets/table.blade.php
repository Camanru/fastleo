{{-- Последние записи раздела. $fields, $rows (cells, url), $allUrl. Строка с адресом нажимается целиком. --}}
@if ($rows === [])
    <p class="text-sm text-ink-2">{{ __('fastleo::dashboard.table_empty') }}</p>
@else
    <div class="-mx-4 sm:-mx-5">
        <table class="w-full table-fixed text-left text-sm">
            <thead>
                <tr class="border-b border-line text-xs text-ink-2">
                    @foreach ($fields as $field)
                        <th scope="col" @class(['truncate px-2 py-1.5 font-[550] first:pl-4 last:pr-4 sm:first:pl-5 sm:last:pr-5'])>{{ $field->getLabel() }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($rows as $row)
                    <tr data-widget-row @if ($row['url']) class="cursor-pointer hover:bg-hover" x-data x-on:click="if (! $event.target.closest('a')) window.location = @js($row['url'])" @endif>
                        @foreach ($row['cells'] as $cell)
                            <td @class(['truncate px-2 py-2 first:pl-4 last:pr-4 sm:first:pl-5 sm:last:pr-5', 'whitespace-nowrap text-ink-2 tabular-nums' => $loop->last && ! $loop->first])>
                                @if ($loop->first && $row['url'])
                                    <a href="{{ $row['url'] }}" class="font-medium text-ink">{{ $cell }}</a>
                                @else
                                    {{ $cell }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
<a href="{{ $allUrl }}" class="text-sm font-medium text-primary-text hover:underline">{{ __('fastleo::dashboard.all_records') }}</a>
