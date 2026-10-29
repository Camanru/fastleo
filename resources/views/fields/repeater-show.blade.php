{{-- Repeater на странице просмотра: таблица элементов. --}}
<div class="overflow-x-auto whitespace-normal">
    <table class="w-full text-left text-sm">
        <thead class="text-xs text-ink-2">
            <tr>
                @foreach ($fields as $nested)
                    <th class="py-1 pr-4 font-medium">{{ $nested->getLabel() }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @foreach ($items as $item)
                <tr>
                    @foreach ($fields as $nested)
                        <td class="py-1.5 pr-4 align-top">
                            @if ($nested->isVisibleOnShow($item))
                                @include('fastleo::resources.cell', ['field' => $nested, 'record' => $item, 'inList' => true])
                            @else
                                <span class="text-ink-3">—</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
