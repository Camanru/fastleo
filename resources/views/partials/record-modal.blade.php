{{-- Окно записи: просмотр (карточка прямо в окне, без iframe; открывает openRecordView в fastleo.js) и форма раздела в iframe
     (связанная таблица с editIn('modal'); открывает openRecordModal). Кроме крестика кнопок в окне нет.
     Карточка занимает столько высоты, сколько ей нужно (до 85% экрана), форма в iframe — 85% (data-frame). --}}
<dialog id="fastleo-record-modal" aria-label="{{ __('fastleo::resources.modal_title') }}" data-title="{{ __('fastleo::resources.modal_title') }}" data-unsaved="{{ __('fastleo::resources.unsaved') }}" data-loading="{{ __('fastleo::resources.related_loading') }}" class="m-auto max-h-[85vh] w-[calc(100%-2rem)] max-w-4xl overflow-hidden rounded-dialog bg-surface p-0 text-ink shadow-pop backdrop:bg-[rgb(10_12_16/.45)] backdrop:backdrop-blur-[3px] data-[frame]:h-[85vh]">
    <div class="relative flex h-full max-h-[85vh] flex-col">
        <button type="button" data-modal-close class="absolute top-2 right-2 z-10 rounded-md p-2 text-ink-2 hover:bg-hover" title="{{ __('fastleo::resources.modal_close') }}">
            <span class="sr-only">{{ __('fastleo::resources.modal_close') }}</span>
            <x-fastleo::icon name="x" class="size-5" />
        </button>
        <div data-record-card class="hidden min-h-0 flex-1 overflow-y-auto"></div>
        <iframe title="{{ __('fastleo::resources.modal_title') }}" class="w-full flex-1" src="about:blank"></iframe>
    </div>
</dialog>
