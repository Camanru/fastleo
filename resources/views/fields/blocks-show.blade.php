{{-- Blocks на странице просмотра: блоки по порядку — тип и значения полей. --}}
<div class="flex flex-col gap-3 whitespace-normal">
    @foreach ($blocks as $block)
        @php($definition = $field->getTypes()[$block['type']])
        <section class="rounded-md border border-line p-3">
            <h4 class="mb-2 flex items-center gap-2 text-xs font-medium text-ink-2 uppercase">
                <x-fastleo::icon :name="$definition->getIcon()" class="size-3.5" />
                {{ $definition->getLabel() }}
            </h4>
            <dl class="flex flex-col gap-2">
                @foreach ($field->schema($block['type'])->fields() as $nested)
                    @continue($nested instanceof \Fastleo\Fields\Hidden || ! $nested->isVisibleOnShow($block['item']))
                    <div class="flex flex-col sm:flex-row sm:gap-3">
                        <dt class="text-ink-2 sm:w-1/4">{{ $nested->getLabel() }}</dt>
                        <dd class="min-w-0 flex-1 break-words">@include('fastleo::resources.cell', ['field' => $nested, 'record' => $block['item'], 'inList' => false])</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endforeach
</div>
