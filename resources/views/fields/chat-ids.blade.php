{{-- ChatIds: номера чатов метками + «Найти чаты бота» (мессенджер и токен — из полей той же формы). --}}
@php
    $old = old($path, $value);
    $tags = array_values(array_filter(is_array($old) ? $old : [], 'is_string'));
    $writable = ! $field->isDisabled() && ! $field->isReadonly();
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    <div
        x-data="fastleoChatIds({
            tags: @js($tags),
            max: {{ $field->getMaxTagLength() }},
            single: @js($field->isSingle()),
            url: @js(route('fastleo.resources.chats', [$resource::slug(), $field->name])),
            record: @js($record?->exists ? (string) $record->getKey() : null),
            messengerField: @js($field->getMessengerField()),
            tokenField: @js($field->getTokenField()),
            empty: @js(__('fastleo::messengers.find_chats_empty')),
        })"
        class="space-y-2"
    >
        <div
            @class([
                'fastleo-input flex min-h-9 flex-wrap items-center gap-1.5 py-1.5 focus-within:border-primary focus-within:shadow-[0_0_0_3px_var(--color-ring)]',
                'fastleo-input-invalid' => $errors->has($path) || $errors->has($path.'.*'),
                'bg-sunken' => ! $writable,
            ])
        >
            @if ($writable)
                <input type="hidden" name="{{ $name }}" value="">
            @endif
            <template x-for="(tag, index) in tags" :key="tag">
                <span class="inline-flex items-center gap-1 rounded-[6px] bg-primary-soft py-0.5 pr-1.5 pl-2 font-mono text-sm font-medium text-primary-text">
                    @if ($writable)
                        <input type="hidden" name="{{ $name }}[]" :value="tag">
                    @endif
                    <span x-text="tag"></span>
                    @if ($writable)
                        <button type="button" class="text-ink-2 hover:text-danger" @click="remove(index)" :aria-label="@js(__('fastleo::resources.tag_remove')) + ' ' + tag">
                            <x-fastleo::icon name="x" class="size-3.5" />
                        </button>
                    @endif
                </span>
            </template>
            @if ($writable)
                <input
                    type="text"
                    id="{{ $id }}"
                    x-model="draft"
                    @keydown.enter.prevent="add()"
                    @input="input()"
                    @keydown.backspace="if (draft === '') removeLast()"
                    @blur="add()"
                    maxlength="{{ $field->getMaxTagLength() }}"
                    placeholder="{{ $field->getPlaceholder() ?? '-1001234567890' }}"
                    @if ($errors->has($path) || $errors->has($path.'.*')) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
                    class="min-w-32 flex-1 border-0 bg-transparent p-0.5 font-mono text-base outline-none focus:ring-0 sm:text-sm"
                >
            @endif
        </div>
        @if ($writable)
            <div class="flex flex-wrap items-center gap-2">
                <x-fastleo::button type="button" size="sm" variant="secondary" icon="search" @click="find()" ::disabled="loading" data-find-chats>{{ __('fastleo::messengers.find_chats') }}</x-fastleo::button>
                <span class="text-xs text-ink-3">{{ __('fastleo::messengers.find_chats_help') }}</span>
            </div>
            <p x-show="error" x-cloak x-text="error" class="text-sm text-danger" role="alert"></p>
            <ul x-show="found.length" x-cloak class="divide-y divide-line rounded-[8px] border border-line">
                <template x-for="chat in found" :key="chat.id">
                    <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <span class="min-w-0">
                            <span class="font-medium text-ink" x-text="chat.title"></span>
                            <span class="text-ink-3" x-text="chat.type"></span>
                            <span class="font-mono text-xs text-ink-3" x-text="chat.id"></span>
                        </span>
                        <x-fastleo::button type="button" size="sm" variant="ghost" icon="plus" @click="choose(chat.id)">{{ __('fastleo::messengers.chat_add') }}</x-fastleo::button>
                    </li>
                </template>
            </ul>
        @endif
    </div>
</x-fastleo::field>
