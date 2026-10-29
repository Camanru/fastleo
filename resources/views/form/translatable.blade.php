{{-- Многоязычное поле: подпись и вкладки языков, под каждой — шаблон поля для своего языка
     (имя title[en], путь title.en). Вкладка с ошибкой отмечена; открыта первая вкладка с ошибкой. --}}
@php
    $locales = \Fastleo\Fastleo::locales();
    $values = is_array($value) ? $value : [];
    $failed = array_values(array_filter($locales, fn ($locale) => $errors->has($path.'.'.$locale)));
    $first = $failed[0] ?? $locales[0];
@endphp
<div x-data="{ locale: @js($first) }" class="flex flex-col gap-1" data-translatable="{{ $path }}">
    <div class="flex flex-wrap items-center gap-2">
        <label for="{{ $id }}-{{ $first }}" class="text-sm font-medium text-ink-2">
            {{ $field->getLabel() }}
            @if ($field->isRequired())<span class="text-danger" aria-hidden="true">*</span>@endif
        </label>
        @if (count($locales) > 1)
            <div class="ml-auto flex gap-0.5 rounded-[8px] bg-sunken p-0.5 text-xs" role="tablist">
                @foreach ($locales as $locale)
                    <button
                        type="button"
                        role="tab"
                        class="relative rounded-[6px] px-2 py-0.5 font-medium uppercase"
                        :class="locale === @js($locale) ? 'bg-surface shadow-[0_1px_2px_rgb(0_0_0/.08)]' : 'text-ink-2'"
                        :aria-selected="locale === @js($locale)"
                        x-on:click="locale = @js($locale)"
                        data-locale="{{ $locale }}"
                    >
                        {{ $locale }}
                        @if (in_array($locale, $failed, true))
                            <span class="absolute -top-0.5 -right-0.5 size-2 rounded-full bg-danger-solid" aria-hidden="true"></span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif
    </div>
    @foreach ($locales as $locale)
        <div x-show="locale === @js($locale)" @if ($locale !== $first) x-cloak @endif lang="{{ $locale }}">
            @include($field->view(), [
                'field' => $field,
                'value' => $values[$locale] ?? null,
                'record' => $record,
                'name' => $name.'['.$locale.']',
                'path' => $path.'.'.$locale,
                'id' => $id.'-'.$locale,
            ])
        </div>
    @endforeach
</div>
