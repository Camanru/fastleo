<?php

declare(strict_types=1);

namespace Fastleo\Installer;

/**
 * Модуль установки: набор файлов из `stubs/modules/<имя>/`, который `fastleo:module` копирует в сайт.
 *
 * Структура каталога повторяет сайт: `fastleo/` — папка разделов (`fastleo.resources.path`), остальное — от корня
 * сайта (`app/…`, `database/…`, `tests/…`). Расширение `.stub` отбрасывается. Каталог `site/` внутри модуля — публичная часть сайта
 * (контроллеры, шаблоны `resources/views/…` и заготовка `routes/web.php.stub` — код маршрутов, который дописывается в конец `routes/web.php` сайта): её копируют, только если владелец ответил «да».
 *
 * ```php
 * new Module('contacts', 'Контакты', 'Телефон, почта и адрес в подвале сайта', '/путь/stubs/modules/contacts');
 * ```
 */
final readonly class Module
{
    /**
     * @param  string  $name  короткое имя для команды (`contacts`)
     * @param  string  $label  название в списке выбора
     * @param  string  $description  что получит сайт
     * @param  string  $directory  каталог с файлами модуля
     * @param  list<string>  $tables  таблицы, которые создаст модуль: если такая уже есть, установка не начнётся
     * @param  list<string>  $uris  адреса сайта без начального `/`, которые займёт публичная часть модуля: занятый маршрут — публичная часть этого модуля не ставится; перед адресом можно указать метод (`POST shop`) — тогда занятым считается только маршрут с этим методом
     * @param  list<string>  $slugs  адреса разделов и страниц настроек, которые добавит модуль: если такой раздел у сайта уже есть, установка не начнётся (иначе панель упадёт на повторном адресе)
     * @param  list<string>  $widgets  строки виджетов для `widgets()` класса `Dashboard` сайта (`{{ namespace }}` — пространство имён разделов)
     * @param  list<string>  $seeders  классы сидеров с примерами данных (`Database\Seeders\PageSeeder`)
     * @param  string|null  $note  что стоит знать после установки (печатается всегда)
     * @param  bool  $takesFallback  публичная часть занимает запасной маршрут `Route::fallback`: если у сайта он уже есть, публичная часть не ставится
     * @param  bool  $takesHome  публичная часть сама отвечает за главную `/`: нетронутый маршрут `welcome` из нового Laravel заменяется
     * @param  string|null  $siteNote  что стоит знать о публичной части (печатается, только когда она записана)
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $directory,
        public array $tables = [],
        public array $uris = [],
        public array $slugs = [],
        public array $widgets = [],
        public array $seeders = [],
        public ?string $note = null,
        public bool $takesFallback = false,
        public bool $takesHome = false,
        public ?string $siteNote = null,
    ) {}
}
