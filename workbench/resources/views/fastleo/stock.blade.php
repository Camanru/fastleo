{{-- Остаток товара в списке: свой шаблон ячейки (listView), значение — $value. --}}
@if ((int) $value === 0)
    <span class="font-medium text-red-600">нет в наличии</span>
@else
    <span>{{ $value }} шт.</span>
@endif
