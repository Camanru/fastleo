{{-- Раздел «SoftNetica». $data (ClientData|null), $stale, $syncedAt. Данные из внешнего сервиса: везде {{ }}, ссылки уже проверены ClientData. --}}
@php
    $text = ['ok' => 'text-success', 'warn' => 'text-warning', 'crit' => 'text-danger', 'mute' => 'text-ink-2'];
    $dot = ['done' => 'bg-success', 'partial' => 'bg-warning', 'missed' => 'bg-danger'];
    $none = __('fastleo::softnetica.no_data');
@endphp
<x-fastleo::layouts.app :title="__('fastleo::softnetica.title')">
    @if ($data?->cabinetUrl)
        <x-slot:actions>
            <x-fastleo::button variant="primary" icon="external-link" :href="$data->cabinetUrl" target="_blank" rel="noopener noreferrer" data-softnetica-cabinet>{{ __('fastleo::softnetica.open_cabinet') }}</x-fastleo::button>
        </x-slot:actions>
    @endif

    <x-fastleo::flash />

    @if ($data === null)
        <p class="text-sm text-ink-2" data-softnetica-waiting>{{ __('fastleo::softnetica.widget_waiting') }}</p>
    @else
        @if ($stale)
            <p role="status" class="rounded-card bg-warning-soft px-4 py-3 text-sm text-warning" data-softnetica-stale>{{ __('fastleo::softnetica.widget_stale', ['time' => \Fastleo\SoftNetica\ClientData::show($syncedAt, true)]) }}</p>
        @endif

        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
            <x-fastleo::card :title="__('fastleo::softnetica.site')" data-softnetica-block="site">
                <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-2 text-sm">
                    <dt class="text-ink-2">{{ __('fastleo::softnetica.status') }}</dt>
                    <dd class="text-right"><x-fastleo::badge :tone="\Fastleo\SoftNetica\ClientData::badgeTone($data->status['tone'])">{{ $data->status['label'] ?? $none }}</x-fastleo::badge></dd>
                    <dt class="text-ink-2">{{ __('fastleo::softnetica.uptime') }}</dt>
                    <dd class="text-right font-medium">{{ $data->uptime === null ? $none : number_format($data->uptime, 2, ',', "\u{00A0}").' %' }}</dd>
                    @if ($data->domain)
                        <dt class="text-ink-2">{{ __('fastleo::softnetica.site_domain') }}</dt>
                        <dd class="text-right break-all">{{ $data->domain }}</dd>
                    @endif
                </dl>
            </x-fastleo::card>

            <x-fastleo::card :title="__('fastleo::softnetica.certificates')" data-softnetica-block="certificates">
                <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-2 text-sm">
                    @foreach (['ssl' => [$data->ssl, 'ssl'], 'domain' => [$data->domainExpiry, 'domain_expiry']] as [$expiry, $label])
                        <dt class="text-ink-2">{{ __('fastleo::softnetica.'.$label) }}</dt>
                        <dd class="text-right">
                            @if ($expiry)
                                <span class="font-medium {{ $text[$expiry['tone']] }}">{{ $expiry['at'] ? __('fastleo::softnetica.expires', ['date' => \Fastleo\SoftNetica\ClientData::show($expiry['at'], true)]) : $none }}</span>
                                @if ($expiry['days'] !== null)
                                    <span class="block text-ink-2">{{ __('fastleo::softnetica.days_left', ['days' => $expiry['days']]) }}</span>
                                @endif
                                @if (($expiry['paid_by_client'] ?? false) === true)
                                    <span class="block text-ink-2">{{ __('fastleo::softnetica.paid_by_client') }}</span>
                                @endif
                            @else
                                <span class="text-ink-2">{{ $none }}</span>
                            @endif
                        </dd>
                    @endforeach
                </dl>
            </x-fastleo::card>
        </div>

        <x-fastleo::card :title="__('fastleo::softnetica.backups')" data-softnetica-block="backups">
            @if ($data->nights === [] || $data->targets === [])
                <p class="text-sm text-ink-2">{{ $none }}</p>
            @else
                <p class="text-sm text-ink-2">{{ __('fastleo::softnetica.backup_nights') }}</p>
                <div class="relative -mx-4 overflow-x-auto px-4 sm:-mx-5 sm:px-5">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs text-ink-2">
                                <th scope="col" class="py-1.5 pr-4 font-[550]"><span class="sr-only">{{ __('fastleo::softnetica.backups') }}</span></th>
                                @foreach ($data->nights as $night)
                                    <th scope="col" class="px-1.5 py-1.5 text-center font-normal tabular-nums">{{ $night['date']->format('d.m') }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($data->targets as $target)
                                <tr data-softnetica-target>
                                    <th scope="row" class="py-2 pr-4 font-medium whitespace-nowrap">{{ $target['label'] }}</th>
                                    @foreach ($data->nights as $night)
                                        @php($state = $night['copies'][$target['target']] ?? null)
                                        <td class="px-1.5 py-2 text-center">
                                            <span class="inline-block size-3 rounded-full {{ $dot[$state] ?? 'bg-line-strong' }}" data-copy="{{ $state ?? 'unknown' }}"></span>
                                            <span class="sr-only">{{ __('fastleo::softnetica.copy_'.($state ?? 'unknown')) }}</span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-4 gap-y-2 text-sm">
                @if ($data->backup)
                    <dt class="text-ink-2">{{ __('fastleo::softnetica.backup_last') }}</dt>
                    <dd class="text-right font-medium {{ $text[$data->backup['tone']] }}">{{ \Fastleo\SoftNetica\ClientData::show($data->backup['last_success_at'], true) }}</dd>
                @endif
                @foreach (['automatic' => 'restore_automatic', 'manual' => 'restore_manual'] as $kind => $label)
                    @if ($data->restore && $data->restore[$kind])
                        <dt class="text-ink-2">{{ __('fastleo::softnetica.restore') }}: {{ mb_strtolower(__('fastleo::softnetica.'.$label)) }}</dt>
                        <dd class="text-right {{ $text[$data->restore[$kind]['tone']] }}">{{ $data->restore[$kind]['result'] ?? $none }} <span class="text-ink-2">({{ \Fastleo\SoftNetica\ClientData::show($data->restore[$kind]['tested_on']) }})</span></dd>
                    @endif
                @endforeach
            </dl>
        </x-fastleo::card>

        <x-fastleo::card :title="__('fastleo::softnetica.security')" data-softnetica-block="security">
            @if ($data->security)
                <p class="flex flex-wrap items-center gap-2 text-sm">
                    <x-fastleo::badge :tone="$data->security['problem'] === null ? 'neutral' : ($data->security['problem'] ? 'danger' : 'success')">{{ $data->security['problem'] === null ? $none : __($data->security['problem'] ? 'fastleo::softnetica.security_problem' : 'fastleo::softnetica.security_ok') }}</x-fastleo::badge>
                    <span class="text-ink-2">{{ __('fastleo::softnetica.checked_at', ['date' => \Fastleo\SoftNetica\ClientData::show($data->security['checked_at'], true)]) }}</span>
                </p>
            @else
                <p class="text-sm text-ink-2">{{ $none }}</p>
            @endif
        </x-fastleo::card>

        <x-fastleo::card :title="__('fastleo::softnetica.invoices')" data-softnetica-block="invoices">
            @if ($data->billing === null)
                <p class="text-sm text-ink-2">{{ __('fastleo::softnetica.no_client') }}</p>
            @else
                @if ($data->billing['overdue'])
                    <p class="rounded-card bg-danger-soft px-3 py-2 text-sm text-danger" data-softnetica-billing="debt">{{ __('fastleo::softnetica.debt') }}: {{ __('fastleo::softnetica.debt_since', ['amount' => \Fastleo\SoftNetica\ClientData::money($data->billing['overdue']['amount']), 'date' => \Fastleo\SoftNetica\ClientData::show($data->billing['overdue']['since'])]) }}</p>
                @elseif ($data->billing['next'])
                    <p class="text-sm" data-softnetica-billing="next"><span class="text-ink-2">{{ __('fastleo::softnetica.next_payment') }}:</span> {{ __('fastleo::softnetica.next_payment_value', ['amount' => \Fastleo\SoftNetica\ClientData::money($data->billing['next']['amount']), 'date' => \Fastleo\SoftNetica\ClientData::show($data->billing['next']['due_at'])]) }}</p>
                @endif

                @if ($data->billing['invoices'] === [])
                    <p class="text-sm text-ink-2">{{ __('fastleo::softnetica.invoices_empty') }}</p>
                @else
                    <div class="relative -mx-4 overflow-x-auto sm:-mx-5">
                        <table class="w-full min-w-[40rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-line text-xs text-ink-2">
                                    <th scope="col" class="py-1.5 pl-4 font-[550] sm:pl-5">{{ __('fastleo::softnetica.invoices') }}</th>
                                    <th scope="col" class="px-2 py-1.5 font-[550]">{{ __('fastleo::softnetica.invoice_period') }}</th>
                                    <th scope="col" class="px-2 py-1.5 text-right font-[550]">{{ __('fastleo::softnetica.invoice_amount') }}</th>
                                    <th scope="col" class="px-2 py-1.5 text-right font-[550]">{{ __('fastleo::softnetica.invoice_remaining') }}</th>
                                    <th scope="col" class="px-2 py-1.5 font-[550]">{{ __('fastleo::softnetica.invoice_due') }}</th>
                                    <th scope="col" class="px-2 py-1.5 font-[550]">{{ __('fastleo::softnetica.invoice_status') }}</th>
                                    <th scope="col" class="py-1.5 pr-4 sm:pr-5"><span class="sr-only">{{ __('fastleo::softnetica.invoice_open') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($data->billing['invoices'] as $invoice)
                                    <tr data-softnetica-invoice>
                                        <td class="py-2 pl-4 font-medium sm:pl-5">{{ $invoice['title'] ?? $invoice['id'] ?? $none }}</td>
                                        <td class="px-2 py-2 whitespace-nowrap text-ink-2 tabular-nums">{{ \Fastleo\SoftNetica\ClientData::show($invoice['period_from']) }} – {{ \Fastleo\SoftNetica\ClientData::show($invoice['period_to']) }}</td>
                                        <td class="px-2 py-2 text-right whitespace-nowrap tabular-nums">{{ \Fastleo\SoftNetica\ClientData::money($invoice['amount']) }}</td>
                                        <td class="px-2 py-2 text-right whitespace-nowrap tabular-nums">{{ \Fastleo\SoftNetica\ClientData::money($invoice['remaining']) }}</td>
                                        <td class="px-2 py-2 whitespace-nowrap tabular-nums">{{ \Fastleo\SoftNetica\ClientData::show($invoice['due_at']) }}</td>
                                        <td class="px-2 py-2"><x-fastleo::badge :tone="\Fastleo\SoftNetica\ClientData::badgeTone($invoice['tone'])">{{ $invoice['status_label'] ?? $none }}</x-fastleo::badge></td>
                                        <td class="py-2 pr-4 text-right sm:pr-5">
                                            @if ($invoice['url'])
                                                <a href="{{ $invoice['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sm font-medium whitespace-nowrap text-primary-text hover:underline">{{ __('fastleo::softnetica.invoice_open') }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($data->billing['url'])
                    <a href="{{ $data->billing['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-primary-text hover:underline">{{ __('fastleo::softnetica.all_invoices') }}</a>
                @endif
            @endif
        </x-fastleo::card>

        <x-fastleo::card :title="__('fastleo::softnetica.reports')" data-softnetica-block="reports">
            @if ($data->reports === [])
                <p class="text-sm text-ink-2">{{ __('fastleo::softnetica.reports_empty') }}</p>
            @else
                <ul class="flex flex-wrap gap-2">
                    @foreach ($data->reports as $report)
                        <li data-softnetica-report>
                            @if ($report['url'])
                                <a href="{{ $report['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-8 items-center gap-1.5 rounded-btn border border-line-strong bg-surface px-3 text-sm font-medium hover:bg-surface-2">{{ $report['label'] }}@if ($report['current']) <span class="text-ink-2">· {{ __('fastleo::softnetica.report_current') }}</span>@endif</a>
                            @else
                                <span class="inline-flex h-8 items-center rounded-btn bg-sunken px-3 text-sm text-ink-2">{{ $report['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fastleo::card>
    @endif
</x-fastleo::layouts.app>
