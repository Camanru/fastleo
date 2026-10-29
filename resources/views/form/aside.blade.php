{{-- Боковая панель: карточка справа от формы. --}}
<div class="fastleo-card flex flex-col gap-5 p-4 sm:p-5">
    @include('fastleo::form.items', ['items' => $layout->children(), 'record' => $record])
</div>
