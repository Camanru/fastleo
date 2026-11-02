<?php

declare(strict_types=1);

namespace Fastleo\Installer;

/**
 * Модуль установки: набор файлов из `stubs/modules/<имя>/`, который `fastleo:module` копирует в сайт.
 *
 * Структура каталога повторяет сайт: `fastleo/` — папка разделов (`fastleo.resources.path`), остальное — от корня
 * сайта (`resources/views/…`, `routes/site/…`, `database/…`). Расширение `.stub` отбрасывается.
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
     * @param  list<string>  $uris  адреса сайта без начального `/`, которые займёт модуль: занятый маршрут — конфликт; перед адресом можно указать метод (`POST leads`) — тогда занятым считается только маршрут с этим методом
     * @param  list<string>  $widgets  строки виджетов для `widgets()` класса `Dashboard` сайта (`{{ namespace }}` — пространство имён разделов)
     * @param  list<string>  $seeders  классы сидеров с примерами данных (`Database\Seeders\PageSeeder`)
     * @param  string|null  $note  что стоит знать после установки (печатается командой)
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $directory,
        public array $tables = [],
        public array $uris = [],
        public array $widgets = [],
        public array $seeders = [],
        public ?string $note = null,
    ) {}
}
