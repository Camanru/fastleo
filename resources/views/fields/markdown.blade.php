{{-- Markdown: вкладки «Текст» и «Просмотр»; HTML просмотра строит сервер (безопасно). --}}
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div x-data="fastleoMarkdown" data-url="{{ route('fastleo.markdown') }}" data-empty="{{ __('fastleo::resources.markdown_empty') }}" data-failed="{{ __('fastleo::resources.markdown_failed') }}" class="flex flex-col gap-2">
        <div class="flex gap-1 text-sm" role="tablist">
            <button type="button" role="tab" :aria-selected="!preview" @click="preview = false"
                class="rounded-md px-3 py-1" :class="preview ? 'text-ink-2 hover:text-ink ' : 'bg-primary-soft font-medium text-ink '">
                {{ __('fastleo::resources.markdown_write') }}
            </button>
            <button type="button" role="tab" :aria-selected="preview" @click="show()"
                class="rounded-md px-3 py-1" :class="preview ? 'bg-primary-soft font-medium text-ink ' : 'text-ink-2 hover:text-ink '">
                {{ __('fastleo::resources.markdown_preview') }}
            </button>
        </div>
        <textarea
            id="{{ $id }}"
            name="{{ $name }}"
            rows="{{ $field->getRows() }}"
            x-ref="text"
            x-show="!preview"
            @if ($field->getMaxLength()) maxlength="{{ $field->getMaxLength() }}" @endif
            @if ($field->getPlaceholder()) placeholder="{{ $field->getPlaceholder() }}" @endif
            @required($field->isRequired())
            @readonly($field->isReadonly())
            @disabled($field->isDisabled())
            @if ($errors->has($path)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            @class(['fastleo-input font-mono', 'fastleo-input-invalid' => $errors->has($path)])
        >{{ fastleo_old($path, $value) }}</textarea>
        <div x-show="preview" x-cloak class="min-h-24 rounded-md border border-line px-4 py-3" data-markdown-preview>
            <p x-show="message" x-text="message" class="text-sm text-ink-2"></p>
            <div class="fastleo-prose" x-html="html"></div>
        </div>
    </div>
</x-fastleo::field>
