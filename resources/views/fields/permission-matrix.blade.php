{{-- PermissionMatrix: права роли «раздел × просмотр/создание/изменение/удаление». Право на изменение требует просмотра. --}}
@php
    $old = old($path, $value);
    $selected = array_values(array_filter(is_array($old) ? $old : [], 'is_string'));
    $writable = ! $field->isDisabled() && ! $field->isReadonly();
    $sections = $field->sections();
    $groups = \Fastleo\Auth\RolePermissions::GROUPS;
@endphp
<x-fastleo::field :field="$field" :id="$id" :path="$path">
    @if ($sections === [])
        <p class="text-sm text-ink-2">{{ __('fastleo::roles.no_sections') }}</p>
    @else
        <div
            class="overflow-x-auto rounded-[10px] border border-line"
            x-data="{
                selected: @js($selected),
                rows: @js(array_map(fn (array $section): array => ['slug' => $section['slug'], 'groups' => $section['groups']], $sections)),
                key(slug, group) { return 'fastleo.' + slug + '.' + group },
                has(slug, group) { return this.selected.includes(this.key(slug, group)) },
                set(slug, group, on) {
                    const name = this.key(slug, group)
                    this.selected = this.selected.filter(item => item !== name)
                    if (on) {
                        this.selected.push(name)
                        if (group !== 'view') this.add(slug, 'view')
                    } else if (group === 'view') {
                        this.selected = this.selected.filter(item => !item.startsWith('fastleo.' + slug + '.'))
                    }
                },
                add(slug, group) {
                    const name = this.key(slug, group)
                    if (!this.selected.includes(name)) this.selected.push(name)
                },
                rowAll(slug, groups) {
                    const all = groups.every(group => this.has(slug, group))
                    groups.forEach(group => this.set(slug, group, !all))
                },
                columnAll(group) {
                    const rows = this.rows.filter(row => row.groups.includes(group))
                    const all = rows.every(row => this.has(row.slug, group))
                    rows.forEach(row => this.set(row.slug, group, !all))
                },
            }"
        >
            @if ($writable)
                <input type="hidden" name="{{ $name }}" value="">
            @endif
            <table class="w-full min-w-[480px] text-base">
                <thead>
                    <tr class="border-b border-line bg-surface-2 text-xs font-[550] text-ink-2">
                        <th scope="col" class="px-4 py-2.5 text-left">{{ __('fastleo::roles.section') }}</th>
                        @foreach ($groups as $group)
                            <th scope="col" class="w-24 px-2 py-2.5 text-center">
                                @if ($writable)
                                    <button type="button" class="rounded-[6px] px-1.5 py-0.5 hover:bg-sunken hover:text-ink" @click="columnAll(@js($group))" title="{{ __('fastleo::roles.select_column', ['group' => __('fastleo::roles.group_'.$group)]) }}">{{ __('fastleo::roles.group_'.$group) }}</button>
                                @else
                                    {{ __('fastleo::roles.group_'.$group) }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sections as $section)
                        <tr @class(['border-line', 'border-t' => ! $loop->first])>
                            <th scope="row" class="px-4 py-2.5 text-left font-medium">
                                @if ($writable)
                                    <button type="button" class="text-left hover:text-primary" @click="rowAll(@js($section['slug']), @js($section['groups']))" title="{{ __('fastleo::roles.select_row', ['section' => $section['label']]) }}">{{ $section['label'] }}</button>
                                @else
                                    {{ $section['label'] }}
                                @endif
                            </th>
                            @foreach ($groups as $group)
                                <td class="px-2 py-2.5 text-center">
                                    @if (in_array($group, $section['groups'], true))
                                        <input
                                            type="checkbox"
                                            @if ($writable) name="{{ $name }}[]" @endif
                                            value="{{ \Fastleo\Auth\RoleMatrix::permission($section['slug'], $group) }}"
                                            data-permission="{{ \Fastleo\Auth\RoleMatrix::permission($section['slug'], $group) }}"
                                            aria-label="{{ $section['label'] }}: {{ mb_strtolower(__('fastleo::roles.group_'.$group)) }}"
                                            class="size-[18px] accent-primary"
                                            :checked="has(@js($section['slug']), @js($group))"
                                            @if ($writable) @change="set(@js($section['slug']), @js($group), $event.target.checked)" @else disabled @endif
                                        >
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-fastleo::field>
