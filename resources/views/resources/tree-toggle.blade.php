{{-- Свернуть / развернуть ветку дерева (состояние запоминается в браузере). У листа — пустое место той же ширины. --}}
@php($treeKey = (string) $record->getKey())
@if ($childCount > 0)
    <button type="button" class="mr-1 inline-flex size-5 items-center justify-center rounded-[5px] align-middle text-ink-3 hover:bg-hover hover:text-ink" @click="toggle(@js($treeKey))" :aria-expanded="!collapsed.includes(@js($treeKey))" aria-label="{{ __('fastleo::resources.tree_toggle') }}" data-tree-toggle>
        <span class="inline-flex transition-transform" :class="collapsed.includes(@js($treeKey)) && '-rotate-90'"><x-fastleo::icon name="chevron-down" class="size-4" /></span>
    </button>
@else
    <span class="mr-1 inline-block size-5 align-middle"></span>
@endif
