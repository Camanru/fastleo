{{-- Раздел «Уведомления»: все свои уведомления за срок хранения. $notifications (simplePaginate), $onlyUnread, $unread. --}}
@php
    // Куда вернуться после «Прочитано»/«Прочитать все»: тот же фильтр и страница.
    $returnTo = array_filter(['unread' => $onlyUnread ? '1' : null, 'page' => $notifications->currentPage() > 1 ? (string) $notifications->currentPage() : null]);
@endphp
<x-fastleo::layouts.app :title="__('fastleo::notifications.title')">
    @if ($unread > 0)
        <x-slot:actions>
            <x-fastleo::action-form :action="route('fastleo.notifications.read-all')">
                @foreach ($returnTo as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <x-fastleo::button type="submit" icon="check-check" data-notifications-read-all>{{ __('fastleo::notifications.read_all') }}</x-fastleo::button>
            </x-fastleo::action-form>
        </x-slot:actions>
    @endif

    <x-fastleo::flash />

    <nav class="flex gap-1 text-sm" aria-label="{{ __('fastleo::notifications.filter') }}" data-notifications-filter>
        <a href="{{ route('fastleo.notifications.index') }}" @class(['rounded-md px-3 py-1.5 font-medium', 'bg-sunken text-ink' => ! $onlyUnread, 'text-ink-2 hover:text-ink' => $onlyUnread]) @if (! $onlyUnread) aria-current="page" @endif>{{ __('fastleo::notifications.filter_all') }}</a>
        <a href="{{ route('fastleo.notifications.index', ['unread' => 1]) }}" @class(['rounded-md px-3 py-1.5 font-medium', 'bg-sunken text-ink' => $onlyUnread, 'text-ink-2 hover:text-ink' => ! $onlyUnread]) @if ($onlyUnread) aria-current="page" @endif>{{ __('fastleo::notifications.filter_unread', ['count' => $unread]) }}</a>
    </nav>

    @if ($notifications->isEmpty())
        <p class="text-sm text-ink-2" data-notifications-empty>{{ $onlyUnread ? __('fastleo::notifications.empty') : __('fastleo::notifications.empty_all') }}</p>
    @else
        <ul class="flex flex-col divide-y divide-line rounded-card border border-line bg-surface" data-notifications-list>
            @foreach ($notifications as $item)
                <li @class(['flex flex-wrap items-start gap-x-3 gap-y-2 p-4 text-sm sm:flex-nowrap', 'opacity-60' => $item['read']]) data-notification="{{ $item['read'] ? 'read' : 'unread' }}">
                    <span aria-hidden="true" @class(['mt-1.5 size-2 shrink-0 rounded-full', match ($item['level']) { 'success' => 'bg-success', 'warning' => 'bg-warning', 'danger' => 'bg-danger', default => 'bg-primary' } => ! $item['read'], 'bg-transparent' => $item['read']])></span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium break-words">@unless ($item['read'])<span class="sr-only">{{ __('fastleo::notifications.unread_mark') }}: </span>@endunless{{ $item['title'] }}</p>
                        @if ($item['body'] !== '')
                            <p class="break-words text-ink-2">{{ $item['body'] }}</p>
                        @endif
                        <p class="text-xs text-ink-3">{{ $item['time'] }}</p>
                    </div>
                    <div class="flex basis-full items-center gap-1 pl-5 sm:basis-auto sm:shrink-0 sm:pl-0">
                        @if ($item['url'] !== null)
                            @if ($item['read'])
                                <x-fastleo::button size="sm" :href="$item['url']" icon="external-link" data-notification-open>{{ __('fastleo::notifications.open_link') }}</x-fastleo::button>
                            @else
                                <x-fastleo::action-form :action="route('fastleo.notifications.read', $item['id'])">
                                    <input type="hidden" name="open" value="1">
                                    <x-fastleo::button type="submit" size="sm" icon="external-link" data-notification-open>{{ __('fastleo::notifications.open_link') }}</x-fastleo::button>
                                </x-fastleo::action-form>
                            @endif
                        @endif
                        @unless ($item['read'])
                            <x-fastleo::action-form :action="route('fastleo.notifications.read', $item['id'])">
                                @foreach ($returnTo as $name => $value)
                                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                @endforeach
                                <x-fastleo::button type="submit" size="sm" icon="check" data-notification-read>{{ __('fastleo::notifications.mark_read') }}</x-fastleo::button>
                            </x-fastleo::action-form>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($notifications->hasPages())
        <div class="flex gap-2">
            @if (! $notifications->onFirstPage())
                <x-fastleo::button icon="chevron-left" :href="$notifications->previousPageUrl()" data-notifications-newer>{{ __('fastleo::notifications.newer') }}</x-fastleo::button>
            @endif
            @if ($notifications->hasMorePages())
                <x-fastleo::button icon="chevron-right" :href="$notifications->nextPageUrl()" data-notifications-older>{{ __('fastleo::notifications.older') }}</x-fastleo::button>
            @endif
        </div>
    @endif
</x-fastleo::layouts.app>
