{{-- Окно выбора файла: файловый менеджер в iframe. Открывают поля Image, File, Gallery и редактор (openPicker в fastleo.js). --}}
<dialog id="fastleo-picker" class="m-auto h-[85vh] w-[calc(100%-2rem)] max-w-6xl overflow-hidden rounded-dialog bg-surface p-0 text-ink shadow-pop backdrop:bg-[rgb(10_12_16/.45)] backdrop:backdrop-blur-[3px]" data-messages="{{ json_encode([
    'too_big' => __('fastleo::files.too_big'),
    'too_big_total' => __('fastleo::files.too_big_total'),
    'error' => __('fastleo::files.error'),
    'session_expired' => __('fastleo::files.session_expired'),
    'too_many' => __('fastleo::files.too_many'),
    'sizes' => [__('fastleo::files.size_bytes'), __('fastleo::files.size_kb'), __('fastleo::files.size_mb'), __('fastleo::files.size_gb')],
], JSON_UNESCAPED_UNICODE) }}" data-upload-url="{{ route('fastleo.files.upload') }}" data-thumb-url="{{ route('fastleo.files.thumb') }}" data-max-file="{{ \Fastleo\Files\UploadLimits::perFile() }}" data-max-total="{{ \Fastleo\Files\UploadLimits::total() }}">
    <div class="flex h-full flex-col">
        <div class="flex items-center justify-between gap-2 border-b border-line px-4 py-2">
            <h2 class="font-semibold">{{ __('fastleo::files.picker_title') }}</h2>
            <form method="dialog">
                <button type="submit" class="rounded-md p-2 text-ink-2 hover:bg-hover" title="{{ __('fastleo::files.picker_close') }}" data-picker-close>
                    <span class="sr-only">{{ __('fastleo::files.picker_close') }}</span>
                    <x-fastleo::icon name="x" class="size-5" />
                </button>
            </form>
        </div>
        <iframe title="{{ __('fastleo::files.picker_title') }}" class="w-full flex-1" src="about:blank"></iframe>
    </div>
</dialog>
