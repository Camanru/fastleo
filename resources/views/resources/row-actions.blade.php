{{-- Кнопки записи в списке: открыть, изменить, «+» вложенная (дерево), удалить / восстановить, удалить навсегда.
     У узла дерева с детьми кнопка удаления недоступна (держит место, чтобы колонка не прыгала).
     Разметка кнопок — как у <x-fastleo::button variant="icon"> и <x-fastleo::action-form>, но без вложенных
     компонентов: на каждую запись списка их десятки, и страница «Все» на 1000 записей иначе рисуется секундами. --}}
@php
    $slug = $resource::slug();
    $rowActions = array_filter($singleActions ?? [], fn ($action) => $actionAllows($action, $record) && $action->visibleFor($record));
    $childCount = $childCount ?? 0;
    // Из связанной таблицы страницы записи: ссылки и удаление возвращают на эту страницу.
    $returnQuery = isset($returnUrl) ? ['return' => $returnUrl] : [];
    $buttonBase = 'inline-flex items-center justify-center whitespace-nowrap transition active:scale-[.98] disabled:pointer-events-none disabled:opacity-50 aria-busy:pointer-events-none ';
    $iconButton = $buttonBase.'size-8 justify-center rounded-[8px] text-ink-2 hover:bg-hover hover:text-ink';
    $dangerButton = $buttonBase.'size-8 justify-center rounded-[8px] text-ink-2 hover:bg-danger-soft hover:text-danger';
    $icon = fn (string $name, string $class = 'shrink-0 size-4') => \Fastleo\Support\Icons::render($name, new \Illuminate\View\ComponentAttributeBag(['class' => $class]));
    $link = fn (string $url, string $iconName, string $label) => new \Illuminate\Support\HtmlString(
        '<a href="'.e($url).'" title="'.e($label).'" class="'.$iconButton.'">'.$icon($iconName).'<span class="sr-only">'.e($label).'</span></a>'
    );
    $formStart = fn (string $url, string $method, string $confirm) => new \Illuminate\Support\HtmlString(
        '<form method="POST" action="'.e($url).'" x-data="fastleoConfirm" data-confirm="'.e($confirm).'" @submit.prevent="ask" class="inline">'
        .csrf_field().($method === 'POST' ? '' : method_field($method))
        .(isset($returnUrl) ? '<input type="hidden" name="_return" value="'.e($returnUrl).'">' : '')
    );
    $submit = fn (string $iconName, string $label, string $class) => new \Illuminate\Support\HtmlString(
        '<button type="submit" title="'.e($label).'" class="'.$class.'">'.$icon($iconName).'<span class="sr-only">'.e($label).'</span></button>'
    );
@endphp
<div class="flex items-center justify-end gap-0.5">
    @if ($trashed)
        @if ($allows('view', $record))
            {{ $link(route('fastleo.resources.show', [$slug, $record->getKey(), ...$returnQuery]), 'eye', __('fastleo::resources.show')) }}
        @endif
        @if ($allows('restore', $record))
            <x-fastleo::action-form :action="route('fastleo.resources.restore', [$slug, $record->getKey()])">
                {{ $submit('rotate-ccw', __('fastleo::resources.restore'), $iconButton) }}
            </x-fastleo::action-form>
        @endif
        @if ($childCount === 0 && $allows('forceDelete', $record))
            {{ $formStart(route('fastleo.resources.force-delete', [$slug, $record->getKey()]), 'DELETE', __('fastleo::resources.confirm_delete_forever')) }}
                {{ $submit('trash-2', __('fastleo::resources.force_delete'), $dangerButton) }}
            </form>
        @endif
    @else
        @if ($allows('view', $record))
            {{ $link(route('fastleo.resources.show', [$slug, $record->getKey(), ...$returnQuery]), 'eye', __('fastleo::resources.show')) }}
        @endif
        @if (($tree ?? null) && $allows('create'))
            <x-fastleo::button variant="ghost" size="sm" icon="plus" class="text-primary-text opacity-0 group-hover/row:opacity-100 focus-visible:opacity-100 max-md:opacity-100" :href="route('fastleo.resources.create', ['resource' => $slug, 'parent' => $record->getKey()])" title="{{ __('fastleo::resources.tree_add_child') }}">
                {{ __('fastleo::resources.tree_add_child_short') }}
            </x-fastleo::button>
        @endif
        @if ($allows('update', $record))
            {{ $link(route('fastleo.resources.edit', [$slug, $record->getKey(), ...$returnQuery]), 'pencil', __('fastleo::resources.edit')) }}
        @endif
        @if ($childCount === 0 && $allows('delete', $record))
            {{ $formStart(route('fastleo.resources.destroy', [$slug, $record->getKey()]), 'DELETE', __($resource::usesSoftDeletes() ? 'fastleo::resources.confirm_delete' : 'fastleo::resources.confirm_delete_forever')) }}
                {{ $submit('trash-2', __('fastleo::resources.delete'), $dangerButton) }}
            </form>
        @elseif ($childCount > 0 && $allows('delete', $record))
            {{-- У ветки с вложенными удалить нельзя; кнопка-заглушка держит место, чтобы колонка не прыгала. --}}
            <x-fastleo::button variant="icon-danger" icon="trash-2" disabled title="{{ __('fastleo::resources.tree_delete_children_first') }}">
                <span class="sr-only">{{ __('fastleo::resources.tree_delete_children_first') }}</span>
            </x-fastleo::button>
        @endif
    @endif
    @if ($rowActions !== [])
        @include('fastleo::actions.menu')
    @endif
</div>
