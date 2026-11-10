{{-- RichText: визуальный редактор HugeRTE поверх textarea. Картинки — из файлового менеджера. HTML чистит сервер. --}}
@php
    $old = old($path, $value);
    // Редактор получает очищенный HTML: после ошибки проверки в него не попадает то, что прислал подделанный запрос.
    $html = is_string($old) ? ($field->sanitize($old) ?? '') : '';
    $writable = $field->isWritable();
    // Сохранённый текст, который чистка не берёт (слишком много тегов), в редактор не попадёт; пустой редактор стёр бы его при сохранении.
    // Поле обычной записи тогда не выводится вовсе: форма его не присылает, и текст в базе остаётся как был. Присланное самим пользователем
    // (ошибка проверки) не считается: оно не сохранено, форма показывает редактор и ошибку.
    // Язык многоязычного поля и подполе вложенного блока (повторитель, конструктор, дочерние записи) так не оставить: значение
    // собирается из присланного, и пропущенное исчезло бы (а у обязательного подполя ещё и не прошла бы проверка). Там текст остаётся
    // в обычном поле как HTML-код и не сохраняется, пока разметку не упростят. Вложенное поле видно по пути (`faq.0.answer`), а не по
    // записи: после ошибки проверки повторитель отдаёт подполям пустую запись.
    $keepsAbsent = ! $field->isTranslatable() && ! str_contains($path, '.');
    $tooComplex = $writable && $keepsAbsent && old($path) === null && is_string($value) && \Fastleo\Support\HtmlSanitizer::tooComplex($value);
    $asSource = $writable && ! $keepsAbsent && is_string($old) && \Fastleo\Support\HtmlSanitizer::tooComplex($old);
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    @if ($tooComplex)
        <p role="alert" class="rounded-card bg-warning-soft px-4 py-3 text-sm text-warning">{{ __('fastleo::resources.rich_text_stored_too_complex') }}</p>
    @elseif ($asSource)
        <p role="alert" class="rounded-card bg-warning-soft px-4 py-3 text-sm text-warning">{{ __('fastleo::resources.rich_text_stored_as_source') }}</p>
        <textarea
            id="{{ $id }}"
            name="{{ $name }}"
            rows="12"
            @class(['fastleo-input font-mono', 'fastleo-input-invalid' => $errors->has($path)])
            @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        >{{ $old }}</textarea>
    @elseif ($writable)
        <div x-data="fastleoRichText(@js(['height' => $field->getHeight(), 'picker' => $field->pickerUrl(), 'blockFormats' => __('fastleo::resources.rich_text_formats'), 'contentCss' => \Fastleo\Support\Html\RichTextSettings::contentCss(), 'contentStyle' => \Fastleo\Support\Html\RichTextSettings::contentStyle()]))" data-rich-text>
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
