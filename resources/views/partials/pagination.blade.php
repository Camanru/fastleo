{{-- Страницы списка: «Записи 1–25 из 120», назад/вперёд, номера, «Все» / «Постранично».
     $bordered (по умолчанию да) — линия сверху, когда строка внутри карточки таблицы; иначе строка сама в рамке (карточки на телефоне). --}}
@php
    $showToggle = $paginator->total() > $paginator->perPage() || $all;
    $bordered ??= true;
    $pageLink = 'flex size-[30px] items-center justify-center rounded-[7px]';
@endphp
@if ($paginator->total() > 0)
    <nav @class(['flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 text-sm text-ink-2', 'border-t border-line' => $bordered, 'rounded-card border border-line bg-surface' => ! $bordered]) aria-label="{{ __('fastleo::resources.list') }}">
        <span class="tabular-nums">
            {{ __('fastleo::resources.shown', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
        </span>

        <div class="flex-1"></div>

        @if ($showToggle)
            <a href="{{ request()->fullUrlWithQuery(['per_page' => $all ? null : 'all', 'page' => null]) }}" class="rounded-[7px] px-2 py-1 font-medium text-primary-text hover:bg-hover">
                {{ __($all ? 'fastleo::resources.per_page_pages' : 'fastleo::resources.per_page_all') }}
            </a>
        @endif

        @if ($paginator->hasPages())
            <div class="flex items-center gap-0.5 tabular-nums">
                @if ($paginator->onFirstPage())
                    <span class="{{ $pageLink }} text-ink-3"><x-fastleo::icon name="chevron-left" class="size-4" /></span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $pageLink }} hover:bg-hover" title="{{ __('fastleo::resources.previous') }}">
                        <x-fastleo::icon name="chevron-left" class="size-4" /><span class="sr-only">{{ __('fastleo::resources.previous') }}</span>
                    </a>
                @endif

                @foreach (array_unique([1, max(1, $paginator->currentPage() - 2), max(1, $paginator->currentPage() - 1), $paginator->currentPage(), min($paginator->lastPage(), $paginator->currentPage() + 1), min($paginator->lastPage(), $paginator->currentPage() + 2), $paginator->lastPage()]) as $page)
                    @if (! $loop->first && $page - $previousPage > 1)
                        <span class="px-1 text-ink-3">…</span>
                    @endif
                    @if ($page === $paginator->currentPage())
                        <span class="{{ $pageLink }} bg-primary-soft font-semibold text-primary-text" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $paginator->url($page) }}" class="{{ $pageLink }} text-ink hover:bg-hover" aria-label="{{ __('fastleo::resources.page', ['page' => $page]) }}">{{ $page }}</a>
                    @endif
                    @php($previousPage = $page)
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $pageLink }} hover:bg-hover" title="{{ __('fastleo::resources.next') }}">
                        <x-fastleo::icon name="chevron-right" class="size-4" /><span class="sr-only">{{ __('fastleo::resources.next') }}</span>
                    </a>
                @else
                    <span class="{{ $pageLink }} text-ink-3"><x-fastleo::icon name="chevron-right" class="size-4" /></span>
                @endif
            </div>
        @endif
    </nav>
@endif
