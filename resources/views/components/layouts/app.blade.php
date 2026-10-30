@props(['title' => null, 'description' => null, 'count' => null, 'heading' => null, 'back' => null, 'crumbs' => null])
{{-- Страницы админки после входа: боковое меню, шапка, заголовок страницы, содержимое.
     title — название страницы (вкладка браузера, крошки), heading — заголовок на странице, если он другой (название записи); description и count — пояснение и счётчик рядом с ним; back — ['url' => …, 'label' => …], ссылка «назад» над заголовком;
     crumbs — хлебные крошки [['label' => …, 'url' => …|null], …] (по умолчанию группа меню и title); слоты actions — кнопки справа на одной линии с заголовком (или x-teleport="#fastleo-page-actions" из содержимого страницы), badge — бейдж у заголовка. --}}
@php
    $menuGroups = \Fastleo\Support\Menu::groups();
    $crumb = null;

    foreach ($menuGroups as $group) {
        foreach ($group['items'] as $item) {
            if ($item['active']) {
                $crumb = $group['label'];
            }
        }
    }

    $crumbs ??= array_values(array_filter([$crumb !== null ? ['label' => $crumb] : null, ['label' => (string) $title]]));
    $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: request()->getHost();
    $host = filter_var($host, FILTER_VALIDATE_IP) === false ? $host : null;

    $user = auth()->user();
    $userName = (string) $user?->getAttribute('name');
    $initials = \Illuminate\Support\Str::of($userName)->explode(' ')->filter()->take(2)->map(fn ($word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word, 0, 1)))->implode('');
    $iconButton = 'flex size-8 items-center justify-center rounded-[8px] text-ink-2 transition-colors hover:bg-hover hover:text-ink';
@endphp
<!DOCTYPE html>
<html lang="ru" class="h-full" data-theme="{{ \Fastleo\Support\Branding::theme() }}">
<head>
    @include('fastleo::partials.head', ['title' => $title])
</head>
<body class="h-full bg-bg text-ink antialiased" x-data="fastleoShell" @keydown.escape.window="menuOpen = false">
    @include('fastleo::partials.assets-warning')
    <div
        x-data="fastleoSearch({ url: @js(route('fastleo.search')) })"
        @keydown.window="if (($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 'k') { $event.preventDefault(); toggle(); }"
        @keydown.escape.window="hide()"
        data-search
    >
        {{-- Затемнение под меню на телефоне --}}
        <div x-cloak x-show="menuOpen" x-transition.opacity class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="menuOpen = false"></div>

        <aside
            id="fastleo-menu"
            class="fixed inset-y-0 left-0 z-40 flex w-[248px] -translate-x-full flex-col gap-3.5 border-r border-line bg-sidebar px-3 pt-3.5 pb-3 transition-[transform,width] duration-200 ease-out-quint lg:translate-x-0 lg:collapsed:w-16 lg:collapsed:px-2"
            :class="menuOpen && 'translate-x-0 shadow-pop'"
        >
            <div class="flex items-center justify-between gap-2 px-1.5 py-0.5 lg:collapsed:justify-center lg:collapsed:px-0">
                <a href="{{ route('fastleo.dashboard') }}" class="min-w-0 lg:collapsed:[&_[data-brand-text]]:sr-only">@include('fastleo::partials.brand', array_filter(['subtitle' => $host]))</a>
                <button type="button" class="{{ $iconButton }} lg:hidden" @click="menuOpen = false">
                    <span class="sr-only">{{ __('fastleo::layout.menu_close') }}</span>
                    <x-fastleo::icon name="x" class="size-4" />
                </button>
            </div>

            <button type="button" class="flex h-8 items-center gap-2 rounded-[8px] border border-line bg-surface px-2.5 text-sm text-ink-3 transition-colors hover:text-ink-2 lg:collapsed:h-9 lg:collapsed:w-10 lg:collapsed:justify-center lg:collapsed:self-center lg:collapsed:border-transparent lg:collapsed:bg-transparent lg:collapsed:px-0 lg:collapsed:hover:bg-hover" @click="menuOpen = false; show()" title="{{ __('fastleo::search.open') }} (Ctrl+K)" data-search-open>
                <x-fastleo::icon name="search" class="size-[15px] shrink-0 lg:collapsed:size-[18px]" />
                <span class="flex-1 text-left lg:collapsed:sr-only">{{ __('fastleo::search.open') }}</span>
                <kbd class="rounded border border-line-strong px-1.5 text-[11px] text-ink-2 lg:collapsed:hidden" data-mac="⌘K">Ctrl K</kbd>
            </button>

            <nav class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto lg:collapsed:gap-2.5 lg:collapsed:overflow-visible" aria-label="{{ __('fastleo::layout.menu') }}">
                <div class="flex flex-col gap-px lg:collapsed:items-center">
                    @include('fastleo::partials.menu-item', ['url' => route('fastleo.dashboard'), 'icon' => 'layout-dashboard', 'label' => __('fastleo::layout.dashboard'), 'active' => request()->routeIs('fastleo.dashboard'), 'badge' => null])
                    {{-- Пункты без группы идут сразу под дашбордом, без отступа под заголовок группы. --}}
                    @foreach (collect($menuGroups)->where('label', null)->flatMap(fn ($group) => $group['items']) as $item)
                        @include('fastleo::partials.menu-item', ['url' => $item['url'], 'icon' => $item['icon'], 'label' => $item['label'], 'active' => $item['active'], 'badge' => $item['badge']])
                    @endforeach
                </div>
                @foreach (collect($menuGroups)->whereNotNull('label') as $group)
                    <div class="flex flex-col gap-px lg:collapsed:items-center lg:collapsed:border-t lg:collapsed:border-line lg:collapsed:pt-2.5">
                        <span class="px-2.5 pb-1.5 text-[11px] font-semibold tracking-[.04em] text-ink-3 uppercase lg:collapsed:hidden">{{ $group['label'] }}</span>
                        @foreach ($group['items'] as $item)
                            @include('fastleo::partials.menu-item', ['url' => $item['url'], 'icon' => $item['icon'], 'label' => $item['label'], 'active' => $item['active'], 'badge' => $item['badge']])
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <div class="flex flex-col gap-2 border-t border-line pt-3 lg:collapsed:items-center">
                <button type="button" class="hidden h-8 items-center gap-2.5 rounded-[7px] px-2.5 text-sm text-ink-2 transition-colors hover:bg-hover hover:text-ink lg:flex lg:collapsed:w-10 lg:collapsed:justify-center lg:collapsed:px-0" @click="toggleCollapsed()" :aria-label="collapsed ? @js(__('fastleo::layout.sidebar_expand')) : @js(__('fastleo::layout.sidebar_collapse'))" :aria-expanded="! collapsed" aria-controls="fastleo-menu">
                    <x-fastleo::icon name="chevrons-left" class="size-4 shrink-0 lg:collapsed:hidden" />
                    <x-fastleo::icon name="chevrons-right" class="hidden size-4 shrink-0 lg:collapsed:block" />
                    <span class="lg:collapsed:sr-only">{{ __('fastleo::layout.sidebar_collapse') }}</span>
                </button>
                <div class="flex items-center gap-1 lg:collapsed:flex-col">
                    <a href="{{ route('fastleo.profile.show') }}" class="flex min-w-0 flex-1 items-center gap-2 rounded-[9px] py-1 pr-2 pl-1 transition-colors hover:bg-hover lg:collapsed:flex-none lg:collapsed:p-0" title="{{ __('fastleo::profile.menu') }}" data-profile-link>
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[11px] font-semibold text-primary-text">{{ $initials ?: '?' }}</span>
                        <span class="flex min-w-0 flex-col lg:collapsed:sr-only">
                            <span class="truncate text-sm font-medium">{{ $userName }}</span>
                            <span class="truncate text-xs text-ink-2">{{ $user?->getAttribute('email') }}</span>
                        </span>
                    </a>
                    <form method="POST" action="{{ route('fastleo.logout') }}">
                        @csrf
                        <button type="submit" class="{{ $iconButton }}" title="{{ __('fastleo::auth.logout') }}">
                            <x-fastleo::icon name="log-out" class="size-4" />
                            <span class="sr-only">{{ __('fastleo::auth.logout') }}</span>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- Глобальный поиск: окно по Ctrl/⌘+K или кнопке в меню --}}
        <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-start justify-center bg-[rgb(10_12_16/.45)] p-4 pt-[10vh] backdrop-blur-[3px]" @click.self="hide()" role="dialog" aria-modal="true" aria-label="{{ __('fastleo::search.open') }}">
            <div class="flex max-h-[75vh] w-full max-w-xl flex-col overflow-hidden rounded-dialog border border-line bg-surface shadow-pop">
                <div class="flex items-center gap-3 border-b border-line px-4">
                    <x-fastleo::icon name="search" class="size-[18px] shrink-0 text-ink-3" />
                    <input
                        type="search"
                        x-ref="input"
                        x-model="query"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="{{ __('fastleo::search.placeholder') }}"
                        class="h-12 min-w-0 flex-1 bg-transparent text-md outline-none focus-visible:shadow-none"
                        @input="input()"
                        @keydown.down.prevent="move(1)"
                        @keydown.up.prevent="move(-1)"
                        @keydown.enter.prevent="go()"
                        data-search-input
                    />
                    <button type="button" class="{{ $iconButton }}" @click="hide()">
                        <span class="sr-only">{{ __('fastleo::search.close') }}</span>
                        <x-fastleo::icon name="x" class="size-4" />
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-1.5" data-search-results>
                    <p x-show="query.trim().length < 2" class="px-4 py-6 text-center text-sm text-ink-2">{{ __('fastleo::search.hint') }}</p>
                    <p x-cloak x-show="failed" class="px-4 py-6 text-center text-sm text-danger">{{ __('fastleo::search.error') }}</p>
                    <p x-cloak x-show="searched && !loading && !failed && groups.length === 0 && query.trim().length >= 2" class="px-4 py-6 text-center text-sm text-ink-2">{{ __('fastleo::search.empty') }}</p>
                    <template x-for="group in groups" :key="group.label">
                        <div class="pb-1.5">
                            <div class="px-2.5 py-1.5 text-[11px] font-semibold tracking-[.04em] text-ink-3 uppercase" x-text="group.label"></div>
                            <ul>
                                <template x-for="(item, position) in group.items" :key="item.url">
                                    <li>
                                        <a
                                            :href="item.url"
                                            class="flex h-9 items-center gap-3 rounded-[7px] px-2.5 text-base"
                                            :class="active === index(group, position) ? 'bg-primary-soft text-primary-text' : 'hover:bg-hover'"
                                            :aria-selected="active === index(group, position)"
                                            @mousemove="active = index(group, position)"
                                            data-search-item
                                        >
                                            <span class="min-w-0 flex-1 truncate" x-text="item.title"></span>
                                            <span class="shrink-0 text-xs text-ink-2" x-text="item.hint"></span>
                                        </a>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <div class="relative flex min-h-full min-w-0 flex-col transition-[padding] duration-200 ease-out-quint lg:pl-[248px] lg:collapsed:pl-16">
            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-line bg-glass px-4 backdrop-blur-[18px] backdrop-saturate-[1.8] lg:px-5">
                <button type="button" class="{{ $iconButton }} -ml-1.5 size-10 lg:hidden" @click="menuOpen = true" aria-controls="fastleo-menu" :aria-expanded="menuOpen">
                    <span class="sr-only">{{ __('fastleo::layout.menu_open') }}</span>
                    <x-fastleo::icon name="menu" class="size-5" />
                </button>

                <h1 class="min-w-0 flex-1 truncate text-md font-semibold lg:hidden">{{ $heading ?? $title }}</h1>
                <nav class="hidden min-w-0 flex-1 items-center gap-1.5 text-base lg:flex" aria-label="{{ __('fastleo::layout.breadcrumbs') }}">
                    @foreach ($crumbs as $item)
                        @if ($loop->last)
                            <span class="truncate font-medium" aria-current="page">{{ $item['label'] }}</span>
                        @else
                            @if (isset($item['url']))
                                <a href="{{ $item['url'] }}" class="shrink-0 text-ink-2 hover:text-ink">{{ $item['label'] }}</a>
                            @else
                                <span class="shrink-0 text-ink-2">{{ $item['label'] }}</span>
                            @endif
                            <x-fastleo::icon name="chevron-right" class="size-3.5 shrink-0 text-ink-3" />
                        @endif
                    @endforeach
                </nav>

                <div
                    x-data="fastleoBell({ url: @js(route('fastleo.notifications.index')), readAllUrl: @js(route('fastleo.notifications.read-all')), readUrl: @js(route('fastleo.notifications.read', ['id' => '__id__'])), unread: {{ \Fastleo\Notifications\AdminNotification::unreadCount() }} })"
                    class="static lg:relative"
                    data-bell
                    @keydown.escape.window="open = false"
                    @click.outside="open = false"
                >
                    <button type="button" class="{{ $iconButton }} relative" @click="toggle()" :aria-expanded="open" aria-haspopup="true" title="{{ __('fastleo::notifications.open') }}">
                        <span class="sr-only">{{ __('fastleo::notifications.open') }}</span>
                        <x-fastleo::icon name="bell" class="size-[17px]" />
                        <span x-cloak x-show="unread > 0" x-text="unread > 99 ? '99+' : unread" class="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-primary px-1 text-center text-[10px] leading-4 font-semibold text-white" data-bell-count></span>
                    </button>
                    <div x-cloak x-show="open" x-transition.opacity class="absolute top-full right-2 left-2 z-50 mt-1 flex max-h-[70vh] flex-col overflow-hidden rounded-[10px] border border-line bg-surface shadow-pop lg:right-0 lg:left-auto lg:mt-2 lg:w-96" role="dialog" aria-label="{{ __('fastleo::notifications.title') }}">
                        <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-2.5">
                            <span class="text-sm font-semibold">{{ __('fastleo::notifications.title') }}</span>
                            <button type="button" x-show="unread > 0" class="text-xs font-medium text-primary-text hover:underline" @click="readAll()">{{ __('fastleo::notifications.read_all') }}</button>
                        </div>
                        <ul class="flex-1 overflow-y-auto" data-bell-list>
                            <template x-for="item in items" :key="item.id">
                                <li class="border-b border-line last:border-0">
                                    <a :href="item.url || '#'" @click="open_(item, $event)" class="flex gap-3 px-4 py-3 text-sm hover:bg-hover" :class="item.read ? 'opacity-60' : ''" data-bell-item>
                                        <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="{ 'bg-primary': item.level === 'info', 'bg-success': item.level === 'success', 'bg-warning': item.level === 'warning', 'bg-danger': item.level === 'danger', 'bg-transparent': item.read }"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium break-words" x-text="item.title"></span>
                                            <span class="block break-words text-ink-2" x-show="item.body" x-text="item.body"></span>
                                            <span class="block text-xs text-ink-3" x-text="item.time"></span>
                                        </span>
                                    </a>
                                </li>
                            </template>
                            <li x-show="loaded && items.length === 0" class="px-4 py-6 text-center text-sm text-ink-2">{{ __('fastleo::notifications.empty') }}</li>
                        </ul>
                    </div>
                </div>

                <div x-data="fastleoTheme" class="flex items-center gap-0.5 rounded-[8px] bg-sunken p-0.5" role="radiogroup" aria-label="{{ __('fastleo::layout.theme') }}">
                    @foreach (['light' => 'sun', 'dark' => 'moon', 'system' => 'monitor'] as $mode => $icon)
                        <button
                            type="button"
                            role="radio"
                            class="flex h-6 w-7 items-center justify-center rounded-[6px] transition-colors"
                            :class="mode === '{{ $mode }}' ? 'bg-surface text-ink shadow-[0_1px_2px_rgb(0_0_0/.1)]' : 'text-ink-3 hover:text-ink'"
                            :aria-checked="mode === '{{ $mode }}'"
                            title="{{ __('fastleo::layout.theme_'.$mode) }}"
                            data-theme-mode="{{ $mode }}"
                            @click="set('{{ $mode }}')"
                        >
                            <span class="sr-only">{{ __('fastleo::layout.theme_'.$mode) }}</span>
                            <x-fastleo::icon :name="$icon" class="size-[15px]" />
                        </button>
                    @endforeach
                </div>
            <a href="{{ route('fastleo.profile.show') }}" class="hidden items-center gap-2 rounded-[9px] py-1 pr-2.5 pl-1 transition-colors hover:bg-hover lg:flex" title="{{ __('fastleo::profile.menu') }}">
                    <span class="flex size-7 items-center justify-center rounded-full bg-primary-soft text-[11px] font-semibold text-primary-text">{{ $initials ?: '?' }}</span>
                    <span class="max-w-32 truncate text-sm font-medium">{{ \Illuminate\Support\Str::of($userName)->explode(' ')->first() }}</span>
                </a>
            </header>

            <main class="flex min-w-0 flex-1 flex-col gap-5 px-4 pt-4 pb-6 lg:px-8 lg:pt-7 lg:pb-8">
                @if (\Fastleo\Closing\SiteClosing::closed())
                    <a href="{{ route('fastleo.settings.edit', 'closing') }}" class="flex flex-wrap items-center gap-2 rounded-[10px] border border-warning bg-warning-soft px-3.5 py-2.5 text-sm font-medium text-warning" data-site-closed>
                        <x-fastleo::icon name="power" class="size-4" />
                        {{ __('fastleo::closing.banner') }}
                    </a>
                @endif
                <div @class(['flex-col gap-1', 'flex' => $count !== null || isset($actions) || $back !== null, 'hidden lg:flex' => $count === null && ! isset($actions) && $back === null]) data-page-header>
                    @if ($back !== null)
                        <a href="{{ $back['url'] }}" class="mb-1 inline-flex w-fit items-center gap-1 text-sm text-ink-2 hover:text-ink" data-back>
                            <x-fastleo::icon name="chevron-left" class="size-3.5" />
                            {{ $back['label'] }}
                        </a>
                    @endif
                    {{-- Заголовок и кнопки страницы — на одной линии; кнопки могут прийти и через x-teleport="#fastleo-page-actions". --}}
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                        <div class="flex min-w-0 flex-wrap items-center gap-2.5">
                            <h1 class="hidden truncate text-2xl font-[650] tracking-[-0.022em] lg:block">{{ $heading ?? $title }}</h1>
                            @isset($badge)
                                {{ $badge }}
                            @endisset
                            @if ($count !== null)
                                <span class="rounded-full bg-sunken px-2 py-px text-sm font-semibold text-ink-2 tabular-nums" data-count>{{ $count }}</span>
                            @endif
                        </div>
                        <div id="fastleo-page-actions" class="flex flex-wrap items-center gap-2 empty:hidden">@isset($actions){{ $actions }}@endisset</div>
                    </div>
                    @if ($description)
                        <p class="hidden text-base text-ink-2 lg:block">{{ $description }}</p>
                    @endif
                </div>

                {{ $slot }}
            </main>
        </div>
    </div>

    @include('fastleo::partials.confirm-dialog')
    @include('fastleo::partials.file-picker')
    @stack('fastleo-scripts')
</body>
</html>
