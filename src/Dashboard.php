<?php

declare(strict_types=1);

namespace Fastleo;

use Fastleo\Widgets\Sections;
use Fastleo\Widgets\ServerStatus;
use Fastleo\Widgets\SoftNetica;
use Fastleo\Widgets\Widget;

/**
 * Главная страница админки: список виджетов.
 *
 * Без своего класса показываются плитки разделов, виджет SoftNetica (только у подключённого сайта) и состояние сервера. Чтобы собрать свой дашборд,
 * создайте класс `Dashboard` рядом с ресурсами (по умолчанию `app/Fastleo/Dashboard.php`):
 *
 * ```php
 * namespace App\Fastleo;
 *
 * use Fastleo\Widgets\{Chart, Sections, ServerStatus, Stat, Table};
 *
 * final class Dashboard extends \Fastleo\Dashboard
 * {
 *     public function widgets(): array
 *     {
 *         return [
 *             Stat::make('Заявки сегодня')->value(fn () => Lead::whereDate('created_at', today())->count())->icon('inbox')->url(route('fastleo.resources.index', 'leads')),
 *             Chart::make('Заявки по дням')->line()->data(fn () => Chart::countByDay(Lead::query())),
 *             Table::make('Последние заявки')->resource(LeadResource::class)->columnSpan(2),
 *             ServerStatus::make()->columnSpan(4),
 *         ];
 *     }
 * }
 * ```
 */
class Dashboard
{
    /**
     * Виджеты в порядке показа (сетка из четырёх колонок; ширина — `columnSpan()`).
     *
     * @return list<Widget>
     */
    public function widgets(): array
    {
        return [
            Sections::make()->columnSpan(4),
            SoftNetica::make()->columnSpan(2),
            ServerStatus::make()->columnSpan(4),
        ];
    }
}
