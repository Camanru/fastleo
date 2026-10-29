{{-- Вложенные записи на странице просмотра: таблица (HasMany) или «поле — значение» (HasOne). --}}
<div class="whitespace-normal">
    @if ($field->isSingle())
        <dl class="flex flex-col gap-2">
            @foreach ($fields as $nested)
                <div class="flex flex-col sm:flex-row sm:gap-3">
                    <dt class="text-ink-2 sm:w-1/3">{{ $nested->getLabel() }}</dt>
                    <dd class="flex-1">
                        @if ($nested->isVisibleOnShow($children->first()))
                            @include('fastleo::resources.cell', ['field' => $nested, 'record' => $children->first(), 'inList' => false])
                        @else
                            <span class="text-ink-3">—</span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-ink-2">
                    <tr>
                        @foreach ($fields as $nested)
                            <th class="py-1 pr-4 font-medium">{{ $nested->getLabel() }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($children as $child)
                        <tr>
                            @foreach ($fields as $nested)
                                <td class="py-1.5 pr-4 align-top">
                                    @if ($nested->isVisibleOnShow($child))
                                        @include('fastleo::resources.cell', ['field' => $nested, 'record' => $child, 'inList' => true])
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
    @endif
</div>
