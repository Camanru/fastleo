{{-- Виджет SoftNetica. $data (ClientData|null), $stale, $syncedAt, $pageUrl. Все значения выводятся экранированно. --}}
@php
    $text = ['ok' => 'text-success', 'warn' => 'text-warning', 'crit' => 'text-danger', 'mute' => 'text-ink-2'];
    $none = __('fastleo::softnetica.no_data');
@endphp
@if ($data === null)
    <p class="text-sm text-ink-2" data-softnetica-waiting>{{ __('fastleo::softnetica.widget_waiting') }}</p>
@else
    @if ($stale)
        <p role="status" class="rounded-card bg-warning-soft px-3 py-2 text-sm text-warning" data-softnetica-stale>{{ __('fastleo::softnetica.widget_stale', ['time' => \Fastleo\SoftNetica\ClientData::show($syncedAt, true)]) }}</p>
    @endif

    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <x-fastleo::badge :tone="\Fastleo\SoftNetica\ClientData::badgeTone($data->status['tone'])" data-softnetica-status>{{ $data->status['label'] ?? $none }}</x-fastleo::badge>
        @if ($data->domain)
            <span class="min-w-0 truncate text-sm text-ink-2">{{ $data->domain }}</span>
        @endif
        <span class="text-sm text-ink-2 sm:ml-auto" data-softnetica-uptime>{{ __('fastleo::softnetica.uptime') }}: <span class="font-medium text-ink">{{ $data->uptime === null ? $none : number_format($data->uptime, 2, ',', "\u{00A0}").' %' }}</span></span>
    </div>

    <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-2 text-sm">
        @php($night = $data->lastNight())
        <dt class="text-ink-2">{{ __('fastleo::softnetica.backup_night') }}</dt>
        <dd class="text-right" data-softnetica-backup>
            @if ($night && $data->lastNightIsFresh())
                <x-fastleo::badge :tone="\Fastleo\SoftNetica\ClientData::badgeTone(\Fastleo\SoftNetica\ClientData::nightTone($night))">{{ \Fastleo\SoftNetica\ClientData::nightSummary($night) }}</x-fastleo::badge>
            @else
                <span class="text-ink-2">{{ __('fastleo::softnetica.backup_none_tonight') }}</span>
            @endif
        </dd>

        @foreach (['ssl' => [$data->ssl, 'ssl'], 'domain' => [$data->domainExpiry, 'domain_expiry']] as $key => [$expiry, $label])
            @if ($expiry)
                <dt class="text-ink-2">{{ __('fastleo::softnetica.'.$label) }}</dt>
                <dd class="text-right" data-softnetica-{{ $key }}>
                    <span class="font-medium {{ $text[$expiry['tone']] }}">{{ $expiry['at'] ? __('fastleo::softnetica.expires', ['date' => \Fastleo\SoftNetica\ClientData::show($expiry['at'], true)]) : $none }}</span>
                    @if ($expiry['days'] !== null)
                        <span class="text-ink-2">· {{ __('fastleo::softnetica.days_left', ['days' => $expiry['days']]) }}</span>
                    @endif
                </dd>
            @endif
        @endforeach

        @if ($data->billing)
            @if ($data->billing['overdue'])
                <dt class="text-ink-2">{{ __('fastleo::softnetica.debt') }}</dt>
                <dd class="text-right font-medium text-danger" data-softnetica-billing="debt">{{ __('fastleo::softnetica.debt_since', ['amount' => \Fastleo\SoftNetica\ClientData::money($data->billing['overdue']['amount']), 'date' => \Fastleo\SoftNetica\ClientData::show($data->billing['overdue']['since'])]) }}</dd>
            @elseif ($data->billing['next'])
                <dt class="text-ink-2">{{ __('fastleo::softnetica.next_payment') }}</dt>
                <dd class="text-right" data-softnetica-billing="next">{{ __('fastleo::softnetica.next_payment_value', ['amount' => \Fastleo\SoftNetica\ClientData::money($data->billing['next']['amount']), 'date' => \Fastleo\SoftNetica\ClientData::show($data->billing['next']['due_at'])]) }}</dd>
            @endif
        @endif
    </dl>

    <a href="{{ $pageUrl }}" class="text-sm font-medium text-primary-text hover:underline" data-softnetica-details>{{ __('fastleo::softnetica.details') }}</a>
@endif
