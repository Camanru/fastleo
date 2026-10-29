{{-- Журнал ошибок. $files, $file, $level, $search, $before, $size, $result. --}}
<x-fastleo::layouts.app :title="__('fastleo::logs.title')">
    <x-fastleo::flash />

    @if ($file === null)
        <p class="text-sm text-ink-2">{{ __('fastleo::logs.no_files') }}</p>
    @else
        <form method="GET" action="{{ route('fastleo.logs.index') }}" class="flex flex-wrap items-end gap-3" data-logs-filters>
            @if (count($files) > 1)
                <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                    {{ __('fastleo::logs.file') }}
                    <select name="file" class="fastleo-input min-w-44">
                        @foreach ($files as $item)
                            <option value="{{ $item['name'] }}" @selected($item['name'] === $file)>{{ $item['name'] }} ({{ \Fastleo\Support\CacheCleaner::format($item['size']) }})</option>
                        @endforeach
                    </select>
                </label>
            @else
                <input type="hidden" name="file" value="{{ $file }}">
            @endif
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                {{ __('fastleo::logs.level') }}
                <select name="level" class="fastleo-input min-w-44">
                    @foreach (__('fastleo::logs.levels') as $key => $label)
                        <option value="{{ $key }}" @selected($key === $level)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm font-medium text-ink-2">
                {{ __('fastleo::logs.search') }}
                <input type="search" name="q" value="{{ $search }}" maxlength="100" class="fastleo-input min-w-44">
            </label>
            <x-fastleo::button type="submit" variant="primary">{{ __('fastleo::logs.apply') }}</x-fastleo::button>
            <span class="text-sm text-ink-2">{{ __('fastleo::logs.size', ['size' => \Fastleo\Support\CacheCleaner::format($size)]) }}</span>
            <div class="flex-1"></div>
        </form>

        <x-fastleo::action-form :action="route('fastleo.logs.clear')" :confirm="__('fastleo::logs.clear_confirm', ['name' => $file, 'size' => \Fastleo\Support\CacheCleaner::format($size)])" :button="__('fastleo::logs.clear')">
            <input type="hidden" name="file" value="{{ $file }}">
            <x-fastleo::button type="submit" variant="danger-soft" icon="trash-2" data-logs-clear>{{ __('fastleo::logs.clear') }}</x-fastleo::button>
        </x-fastleo::action-form>

        @if ($result['entries'] === [])
            <p class="text-sm text-ink-2" data-logs-empty>{{ $result['next'] !== null ? __('fastleo::logs.scan_limit') : __('fastleo::logs.empty') }}</p>
        @else
            <ul class="flex flex-col divide-y divide-line rounded-card border border-line bg-surface" data-logs-list>
                @foreach ($result['entries'] as $entry)
                    <li class="flex flex-col gap-1 p-4 text-sm" data-log-entry="{{ $entry['level'] }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-fastleo::badge :dot="false" :tone="match (true) { in_array($entry['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true) => 'danger', $entry['level'] === 'WARNING' => 'warning', default => 'neutral' }">{{ $entry['level'] }}</x-fastleo::badge>
                            <span class="text-ink-2">{{ $entry['time']->timezone(\Fastleo\Fastleo::timezone())->format('d.m.Y H:i:s') }} · {{ $entry['env'] }}</span>
                        </div>
                        <p class="break-words">{{ $entry['message'] }}</p>
                        @if ($entry['details'] !== '')
                            <details>
                                <summary class="cursor-pointer text-primary-text">{{ __('fastleo::logs.details') }}</summary>
                                <pre class="mt-2 max-h-96 overflow-auto rounded-md bg-surface-2 p-3 text-xs whitespace-pre-wrap">{{ $entry['details'] }}</pre>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($result['next'] !== null)
            <div>
                <x-fastleo::button icon="chevron-down" :href="route('fastleo.logs.index', ['file' => $file, 'level' => $level, 'q' => $search, 'before' => $result['next']])" data-logs-older>{{ __('fastleo::logs.older') }}</x-fastleo::button>
            </div>
        @endif
    @endif
</x-fastleo::layouts.app>
