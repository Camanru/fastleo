{{-- Под формой настроек SoftNetica: состояние связи и действия. $settings, $status (Status::current()). --}}
@php($zone = \Fastleo\Fastleo::timezone())
<x-fastleo::card :title="__('fastleo::softnetica.status_title')" :description="__('fastleo::softnetica.status_help')" data-softnetica-status>
    <div class="flex flex-wrap items-center gap-2">
        @if (! $status['hasToken'])
            <x-fastleo::badge data-softnetica-state="off">{{ __('fastleo::softnetica.state_off') }}</x-fastleo::badge>
        @elseif ($status['rejected'])
            <x-fastleo::badge tone="danger" data-softnetica-state="rejected">{{ __('fastleo::softnetica.state_rejected') }}</x-fastleo::badge>
        @elseif ($status['syncedAt'] === null)
            <x-fastleo::badge tone="warning" data-softnetica-state="waiting">{{ __('fastleo::softnetica.state_waiting') }}</x-fastleo::badge>
        @elseif ($status['stale'])
            <x-fastleo::badge tone="warning" data-softnetica-state="stale">{{ __('fastleo::softnetica.state_stale') }}</x-fastleo::badge>
        @else
            <x-fastleo::badge tone="success" data-softnetica-state="connected">{{ __('fastleo::softnetica.state_connected') }}</x-fastleo::badge>
        @endif
    </div>

    @if ($status['rejected'])
        <p role="alert" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ __('fastleo::softnetica.rejected_text') }}</p>
    @endif

    <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
        <dt class="text-ink-2">{{ __('fastleo::softnetica.synced_at') }}</dt>
        <dd class="font-medium" data-softnetica-synced>{{ $status['syncedAt']?->timezone($zone)->format('d.m.Y H:i') ?? __('fastleo::softnetica.never') }}</dd>
        @if ($status['attemptedAt'] && $status['result'])
            <dt class="text-ink-2">{{ __('fastleo::softnetica.attempted_at') }}</dt>
            <dd>{{ $status['attemptedAt']->timezone($zone)->format('d.m.Y H:i') }} — {{ $status['result']->label() }}</dd>
        @endif
    </dl>

    @if ($status['hasToken'])
        <div class="flex flex-wrap items-center gap-2">
            <x-fastleo::action-form :action="route('fastleo.softnetica.check')">
                <x-fastleo::button type="submit" icon="refresh-cw" data-softnetica-check>{{ __('fastleo::softnetica.check') }}</x-fastleo::button>
            </x-fastleo::action-form>
            <x-fastleo::action-form :action="route('fastleo.softnetica.disconnect')" :confirm="__('fastleo::softnetica.disconnect_confirm')" :button="__('fastleo::softnetica.disconnect')">
                <x-fastleo::button type="submit" variant="danger-soft" icon="unplug" data-softnetica-disconnect>{{ __('fastleo::softnetica.disconnect') }}</x-fastleo::button>
            </x-fastleo::action-form>
        </div>
    @endif
</x-fastleo::card>
