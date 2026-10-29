{{-- Плитки разделов. $items (label, url, icon, badge). --}}
@if ($items === [])
    <p class="text-sm text-ink-2">{{ __('fastleo::dashboard.sections_empty') }}</p>
@else
    <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @foreach ($items as $item)
            <li>
                <a href="{{ $item['url'] }}" class="flex items-center gap-3 rounded-lg border border-line px-3 py-2.5 text-sm font-medium hover:bg-hover" data-section-tile>
                    <x-fastleo::icon :name="$item['icon']" class="size-5 shrink-0 text-primary-text" />
                    <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                    @if ($item['badge'] !== null)
                        <span class="rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-white" data-badge>{{ $item['badge'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
@endif
