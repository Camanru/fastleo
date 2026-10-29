{{-- Ручной порядок записи: ручка перетаскивания и номер ($handle) или стрелки «выше» / «ниже» ($arrows).
     $first / $last — крайняя запись раздела: стрелка в эту сторону недоступна. --}}
@if ($canReorder($record))
    <div class="flex items-center gap-0.5">
        @if ($handle)
            <button type="button" data-reorder-handle class="flex size-[30px] cursor-grab touch-none items-center justify-center rounded-[7px] text-ink-3 hover:bg-hover hover:text-ink" title="{{ __('fastleo::resources.order_drag') }}" aria-label="{{ __('fastleo::resources.order_drag') }}">
                <x-fastleo::icon name="grip-vertical" class="size-[18px]" />
            </button>
            @isset($position)
                <span class="w-[22px] text-sm text-ink-3 tabular-nums" data-position>{{ $position }}</span>
            @endisset
        @endif
        @if ($arrows ?? true)
            @foreach (['up' => 'chevron-up', 'down' => 'chevron-down'] as $direction => $icon)
                <x-fastleo::action-form :action="route('fastleo.resources.reorder', $slug)">
                    <input type="hidden" name="id" value="{{ $record->getKey() }}">
                    <input type="hidden" name="direction" value="{{ $direction }}">
                    <button type="submit" class="flex size-[30px] items-center justify-center rounded-[7px] text-ink-2 hover:bg-hover hover:text-ink disabled:pointer-events-none disabled:text-line-strong" title="{{ __('fastleo::resources.order_'.$direction) }}" data-reorder="{{ $direction }}" @disabled($direction === 'up' ? ($first ?? false) : ($last ?? false))>
                        <x-fastleo::icon :name="$icon" class="size-4" />
                        <span class="sr-only">{{ __('fastleo::resources.order_'.$direction) }}</span>
                    </button>
                </x-fastleo::action-form>
            @endforeach
        @endif
    </div>
@endif
