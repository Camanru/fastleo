{{-- Формы действий раздела внутри x-data="fastleoActions": скрытая форма (без полей) или окно с мини-формой.
     Ключи записей приходят из target компонента. $actions — действия по ключу; $relation и $relationParent — имя связанной
     таблицы и ключ родителя, если форма внутри неё (при ошибке проверки окно откроет сама таблица этого родителя, см. RelatedController). --}}
@foreach ($actions as $key => $action)
    @if ($action->hasForm())
        <dialog id="fastleo-action-{{ $key }}" class="m-auto w-full max-w-lg rounded-dialog bg-surface p-6 text-ink shadow-pop backdrop:bg-[rgb(10_12_16/.45)] backdrop:backdrop-blur-[3px]">
            <form method="POST" action="{{ route('fastleo.resources.action', [$resource::slug(), $key]) }}" class="flex flex-col gap-4">
                @csrf
                @isset($relation)
                    <input type="hidden" name="_related" value="{{ $relation }}">
                    <input type="hidden" name="_related_parent" value="{{ $relationParent ?? '' }}">
                @endisset
                <h2 class="text-lg font-semibold">{{ $action->getLabel() }}</h2>
                @if ($action->getConfirm())
                    <p class="text-sm text-ink-2">{{ $action->getConfirm() }}</p>
                @endif
                <template x-for="id in target" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                @include('fastleo::form.items', [
                    'items' => $action->schema()->items(),
                    'record' => null,
                    'namePrefix' => 'data',
                    'pathPrefix' => 'data',
                    'idPrefix' => 'action-'.$key.'-',
                ])
                <div class="mt-2 flex justify-end gap-2">
                    <x-fastleo::button x-on:click="$el.closest('dialog').close()">{{ __('fastleo::resources.cancel') }}</x-fastleo::button>
                    <x-fastleo::button type="submit" :variant="$action->isDanger() ? 'danger' : 'primary'">{{ $action->getLabel() }}</x-fastleo::button>
                </div>
            </form>
        </dialog>
    @else
        <form id="fastleo-action-form-{{ $key }}" method="POST" action="{{ route('fastleo.resources.action', [$resource::slug(), $key]) }}" hidden>
            @csrf
            <template x-for="id in target" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
        </form>
    @endif
@endforeach
