<x-fastleo::layouts.app title="Отчёт по статьям">
    <x-fastleo::card title="Статьи по статусам" class="max-w-md">
        <dl class="flex flex-col gap-2 text-sm">
            @foreach ($rows as $status => $count)
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-600 dark:text-gray-400">{{ $status }}</dt>
                    <dd class="font-medium" data-report-count>{{ $count }}</dd>
                </div>
            @endforeach
        </dl>
    </x-fastleo::card>
</x-fastleo::layouts.app>
