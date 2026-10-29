@props(['action'])
{{-- Карточка формы на страницах без входа: CSRF, плашка статуса и первая ошибка над полями. --}}
<form method="POST" action="{{ $action }}" {{ $attributes->class(['flex flex-col gap-[18px] rounded-dialog border border-line bg-surface p-7 shadow-pop']) }}>
    @csrf

    @if (session('status'))
        <div class="flex gap-2.5 rounded-[10px] bg-success-soft px-3.5 py-3 text-sm text-success" role="status">
            <x-fastleo::icon name="circle-check" class="mt-px size-4 shrink-0" />
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="flex gap-2.5 rounded-[10px] bg-danger-soft px-3.5 py-3 text-sm text-danger" role="alert">
            <x-fastleo::icon name="circle-alert" class="mt-px size-4 shrink-0" />
            {{ $errors->first() }}
        </div>
    @endif

    {{ $slot }}
</form>
