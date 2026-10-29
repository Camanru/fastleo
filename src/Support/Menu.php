<?php

declare(strict_types=1);

namespace Fastleo\Support;

use Fastleo\Activity\ActivityRecorder;
use Fastleo\Fastleo;
use Fastleo\Files\FileStore;
use Fastleo\Logs\LogReader;
use Fastleo\Resource;

/**
 * Меню админки: разделы-ресурсы (группа, позиция, иконка, счётчик), «Файлы» (группа «Медиа»),
 * «Журнал изменений» и «Журнал ошибок» (группа «Система») и свои ссылки из конфига.
 *
 * Разделы без права `viewAny` не показываются. Группы идут в порядке `fastleo.menu.groups`,
 * остальные — по алфавиту; пункты без группы — сразу под «Дашбордом».
 */
final class Menu
{
    /**
     * Группы меню с пунктами.
     *
     * @return list<array{label: ?string, items: list<array{label: string, url: string, icon: string, badge: ?int, active: bool}>}>
     */
    public static function groups(): array
    {
        $entries = [];

        foreach (Fastleo::resources()->all() as $slug => $class) {
            $resource = Fastleo::resources()->find($slug);

            if (! $resource instanceof Resource || ! $resource::inMenu() || ! Authorization::allows('viewAny', $resource)) {
                continue;
            }

            $badge = $class::badge();

            $entries[] = [
                'group' => $class::group(),
                'position' => $class::position(),
                'label' => $class::label(),
                'url' => route('fastleo.resources.index', $slug),
                'icon' => $class::icon(),
                'badge' => $badge !== null && $badge > 0 ? $badge : null,
                'active' => request()->route('resource') === $slug,
            ];
        }

        $user = Fastleo::guard()->user();

        foreach (Fastleo::resources()->settings() as $slug => $class) {
            if (Fastleo::resources()->findSettings($slug)?->canAccess($user) === true) {
                $entries[] = [
                    'group' => $class::group(),
                    'position' => $class::position(),
                    'label' => $class::label(),
                    'url' => route('fastleo.settings.edit', $slug),
                    'icon' => $class::icon(),
                    'badge' => null,
                    'active' => request()->routeIs('fastleo.settings.*') && request()->route('page') === $slug,
                ];
            }
        }

        foreach (Fastleo::resources()->pages() as $slug => $class) {
            $page = Fastleo::resources()->findPage($slug);

            if ($page?->isAvailable() === true && $page->canAccess($user)) {
                $entries[] = [
                    'group' => $class::group(),
                    'position' => $class::position(),
                    'label' => $class::label(),
                    'url' => route('fastleo.pages.show', $slug),
                    'icon' => $class::icon(),
                    'badge' => null,
                    'active' => request()->routeIs('fastleo.pages.show') && request()->route('page') === $slug,
                ];
            }
        }

        $systemGroup = __('fastleo::users.group', [], Fastleo::LOCALE);
        $mediaGroup = __('fastleo::files.group', [], Fastleo::LOCALE);

        if (FileStore::canManage()) {
            $entries[] = [
                'group' => is_string($mediaGroup) ? $mediaGroup : null,
                'position' => 0,
                'label' => __('fastleo::files.title', [], Fastleo::LOCALE),
                'url' => route('fastleo.files.index'),
                'icon' => 'folder-open',
                'badge' => null,
                'active' => request()->routeIs('fastleo.files.*'),
            ];
        }

        if (ActivityRecorder::canView()) {
            $entries[] = [
                'group' => is_string($systemGroup) ? $systemGroup : null,
                'position' => 50,
                'label' => __('fastleo::activity.title', [], Fastleo::LOCALE),
                'url' => route('fastleo.activity.index'),
                'icon' => 'history',
                'badge' => null,
                'active' => request()->routeIs('fastleo.activity.*'),
            ];
        }

        if (LogReader::canView()) {
            $entries[] = [
                'group' => is_string($systemGroup) ? $systemGroup : null,
                'position' => 900,
                'label' => __('fastleo::logs.title', [], Fastleo::LOCALE),
                'url' => route('fastleo.logs.index'),
                'icon' => 'bug',
                'badge' => null,
                'active' => request()->routeIs('fastleo.logs.*'),
            ];
        }

        foreach (config()->array('fastleo.menu.links', []) as $link) {
            if (is_array($link) && is_string($link['label'] ?? null) && is_string($link['url'] ?? null)) {
                $entries[] = [
                    'group' => is_string($link['group'] ?? null) ? $link['group'] : null,
                    'position' => is_int($link['position'] ?? null) ? $link['position'] : 0,
                    'label' => $link['label'],
                    'url' => $link['url'],
                    'icon' => is_string($link['icon'] ?? null) ? $link['icon'] : 'link',
                    'badge' => null,
                    'active' => false,
                ];
            }
        }

        usort($entries, fn (array $a, array $b): int => [$a['position'], $a['label']] <=> [$b['position'], $b['label']]);

        $groups = [];

        foreach ($entries as $entry) {
            $groups[$entry['group'] ?? ''][] = [
                'label' => $entry['label'],
                'url' => $entry['url'],
                'icon' => $entry['icon'],
                'badge' => $entry['badge'],
                'active' => $entry['active'],
            ];
        }

        $order = array_values(array_filter(config()->array('fastleo.menu.groups', []), is_string(...)));

        uksort($groups, function (string $a, string $b) use ($order): int {
            $rank = fn (string $group): array => $group === ''
                ? [0, 0, '']
                : [1, in_array($group, $order, true) ? (int) array_search($group, $order, true) : PHP_INT_MAX, $group];

            return $rank($a) <=> $rank($b);
        });

        $result = [];

        foreach ($groups as $label => $items) {
            $result[] = ['label' => $label === '' ? null : (string) $label, 'items' => $items];
        }

        return $result;
    }
}
