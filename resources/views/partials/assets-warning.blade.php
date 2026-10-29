{{-- Подсказка разработчику: при APP_DEBUG=true и неопубликованных ассетах страница открылась бы без стилей и молча.
     Стили встроены, потому что файлов пакета на странице как раз нет. --}}
@if (config('app.debug') && ! \Fastleo\Support\Assets::published())
    <div role="alert" style="position:fixed;inset:0 0 auto 0;z-index:9999;padding:12px 16px;background:#fef3c7;color:#78350f;border-bottom:1px solid #f59e0b;font:14px/1.4 system-ui,sans-serif">
        {{ __('fastleo::layout.assets_missing') }}
        <code style="font-family:ui-monospace,monospace">php artisan vendor:publish --tag=fastleo-assets --force</code>
    </div>
@endif
