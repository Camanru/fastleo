{{-- Кнопки действий одной записи (просмотр, редактирование) внутри x-data="fastleoActions". --}}
@foreach ($recordActions as $key => $action)
    <x-fastleo::button
        :icon="$action->getIcon()"
        @class(['text-danger ' => $action->isDanger()])
        x-on:click="run({{ \Illuminate\Support\Js::from($key) }}, [{{ \Illuminate\Support\Js::from((string) $record->getKey()) }}], {{ \Illuminate\Support\Js::from($action->clientOptions()) }})"
    >
        {{ $action->getLabel() }}
    </x-fastleo::button>
@endforeach
