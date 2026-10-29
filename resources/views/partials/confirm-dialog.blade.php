{{-- Своё окно подтверждения вместо confirm() браузера: его открывают формы с x-data="fastleoConfirm".
     На телефоне — нижний лист. --}}
<dialog id="fastleo-confirm" class="m-auto w-full max-w-[420px] rounded-dialog border border-line bg-surface p-6 text-ink shadow-pop backdrop:bg-[rgb(10_12_16/.45)] backdrop:backdrop-blur-[3px] max-sm:mb-0 max-sm:max-w-none max-sm:rounded-b-none max-sm:px-5 max-sm:pt-6 max-sm:pb-9">
    <div class="flex flex-col gap-4">
        <span class="flex size-11 items-center justify-center rounded-full bg-danger-soft text-danger"><x-fastleo::icon name="circle-alert" class="size-5" /></span>
        <div class="flex flex-col gap-1.5">
            <h2 class="text-lg font-semibold tracking-[-0.01em]">{{ __('fastleo::resources.confirm_title') }}</h2>
            <p class="text-base text-ink-2" data-confirm-text></p>
        </div>
        <form method="dialog" class="flex justify-end gap-2 max-sm:flex-col-reverse [&>*]:max-sm:h-11 [&>*]:max-sm:w-full">
            <x-fastleo::button type="submit" value="cancel" autofocus>{{ __('fastleo::resources.cancel') }}</x-fastleo::button>
            <x-fastleo::button type="submit" value="confirm" variant="danger" data-confirm-ok data-default="{{ __('fastleo::resources.delete') }}">{{ __('fastleo::resources.delete') }}</x-fastleo::button>
            <x-fastleo::button type="submit" value="confirm" variant="primary" data-confirm-primary data-default="{{ __('fastleo::actions.confirm') }}" hidden>{{ __('fastleo::actions.confirm') }}</x-fastleo::button>
        </form>
    </div>
</dialog>
