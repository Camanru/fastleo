{{-- RichText: визуальный редактор HugeRTE поверх textarea. Картинки — из файлового менеджера. HTML чистит сервер. --}}
@php
    $old = old($path, $value);
    // Редактор получает очищенный HTML: после ошибки проверки в него не попадает то, что прислал подделанный запрос.
    $html = is_string($old) ? ($field->sanitize($old) ?? '') : '';
    $writable = $field->isWritable();
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    @if ($writable)
        <div x-data="fastleoRichText(@js(['height' => $field->getHeight(), 'picker' => $field->pickerUrl()]))" data-rich-text>
            <textarea
                id="{{ $id }}"
                name="{{ $name }}"
                x-ref="text"
                rows="12"
                @class(['fastleo-input font-mono', 'fastleo-input-invalid' => $errors->has($path)])
                @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            >{{ $html }}</textarea>
        </div>
        @pushOnce('fastleo-scripts', 'fastleo-hugerte')
            <script defer src="{{ \Fastleo\Support\Assets::url('hugerte.js') }}"></script>
        @endPushOnce
    @else
        <div class="fastleo-input min-h-24 bg-surface-2">
            @if ($record)
                {{ $field->display($field->isTranslatable() ? \Fastleo\Support\JsonItem::from([$field->name => $value]) : $record) }}
            @endif
        </div>
    @endif
</x-fastleo::field>
