{{-- Вкладка вне Tabs выводится как обычная группа полей. --}}
<div class="flex flex-col gap-5">
    @include('fastleo::form.items', ['items' => $layout->children(), 'record' => $record])
</div>
