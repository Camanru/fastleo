{{-- Быстрый переключатель в списке: значок-кнопка. Без JS — обычная форма (возврат на страницу), с Alpine — запрос без перезагрузки.
     Нужны $resource, $record, $field (Checkbox с quickToggle()). Два вида значка лежат в разметке, Alpine показывает нужный. --}}
@php($on = (bool) $record->getAttribute($field->name))
<form
    method="POST"
    action="{{ route('fastleo.resources.toggle', [$resource::slug(), $record->getKey(), $field->name]) }}"
    x-data="fastleoQuickToggle({ on: @js($on), failed: @js(__('fastleo::resources.quick_toggle_failed')) })"
    @submit.prevent="send"
    class="inline"
>
    @csrf
    <input type="hidden" name="value" value="{{ $on ? '0' : '1' }}" :value="on ? '0' : '1'">
    <button
        type="submit"
        class="-mx-1.5 cursor-pointer rounded-[6px] px-1.5 py-0.5 hover:bg-hover focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-60"
        title="{{ __('fastleo::resources.quick_toggle', ['field' => $field->getLabel()]) }}"
        :aria-pressed="on"
        :disabled="busy"
        aria-pressed="{{ $on ? 'true' : 'false' }}"
    >
        <span x-show="on" @unless ($on) style="display: none" @endunless>{{ $field->badge(true) }}</span>
        <span x-show="! on" @if ($on) style="display: none" @endif>{{ $field->badge(false) }}</span>
        <span class="sr-only">{{ __('fastleo::resources.quick_toggle', ['field' => $field->getLabel()]) }}</span>
    </button>
    <span x-show="error" x-text="error" role="alert" class="ml-1.5 text-xs text-danger" style="display: none"></span>
</form>
