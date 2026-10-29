{{-- Поля и контейнеры раскладки по порядку. Скрытые поля и пустые контейнеры не выводятся.
     Шаблон поля получает $name (имя в HTML), $path (ключ для old() и ошибок) и $id. Во вложенных блоках
     их префиксы — $namePrefix (prices[0]), $pathPrefix (prices.0), $idPrefix (prices-0-).
     $defaults — значения новой записи из адреса (родитель в дереве: ?parent=).
     Поле с visibleWhen() обёрнуто: показывается, пока управляющее поле (в том же блоке) имеет нужное значение. --}}
@foreach ($items as $item)
    @if ($item instanceof \Fastleo\Fields\Field)
        @if ($item->isVisibleInForm($record))
            @if ($item->getVisibleWhen())
                <div x-data="fastleoVisible(@js($item->getVisibleWhen()))" x-show="shown" x-cloak data-visible-when="{{ $item->getVisibleWhen()['field'] }}">
            @endif
            @include($item->isTranslatable() ? 'fastleo::form.translatable' : $item->view(), [
                'field' => $item,
                'value' => ! isset($namePrefix) && $record === null && array_key_exists($item->name, $defaults ?? []) ? $defaults[$item->name] : $item->formValue($record),
                'record' => $record,
                'name' => isset($namePrefix) ? $namePrefix.'['.$item->name.']' : $item->name,
                'path' => isset($pathPrefix) ? $pathPrefix.'.'.$item->name : $item->name,
                'id' => 'field-'.($idPrefix ?? '').$item->name,
            ])
            @if ($item->getVisibleWhen())
                </div>
            @endif
        @endif
    @elseif ($item->hasVisibleFields($record))
        @include($item->view(), ['layout' => $item, 'record' => $record, 'card' => $card ?? false, 'cards' => $cards ?? false])
    @endif
@endforeach
