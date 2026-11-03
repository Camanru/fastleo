{{-- Запись сохранена в окне (iframe): сообщаем окну-родителю, он закроет окно и обновит таблицу. --}}
<x-fastleo::layouts.frame :title="__('fastleo::resources.saved')">
    <div x-data x-init="window.parent.postMessage({ type: 'fastleo:saved' }, window.location.origin)" class="py-10 text-center text-sm text-ink-2">
        {{ __('fastleo::resources.saved') }}
    </div>
</x-fastleo::layouts.frame>
