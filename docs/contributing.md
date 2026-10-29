# Разработка пакета

Fastleo — Composer-пакет. Он работает внутри сайта на Laravel или в [Orchestra Testbench](https://packages.tools/testbench) — для разработки отдельный сайт не нужен.

## Требования

- PHP 8.4+ с расширениями `pdo_sqlite`, `pcov` (покрытие тестами)
- Composer 2
- Node.js 24+ и npm — только для сборки CSS/JS и браузерных тестов

## Начало

```bash
git clone https://github.com/Camanru/fastleo.git
cd fastleo
composer update
npm ci
npx playwright install chromium   # для браузерных тестов
```

Ветка `master` содержит выпущенные версии: каждая версия — один коммит с тегом. Ошибки и предложения присылайте через issues; pull request тоже пригодится, но его не вливают напрямую — автор переносит правку в рабочую ветку, и она выходит в следующей версии.

## Демо-админка

```bash
composer serve
```

Команда пересоздаёт базу SQLite демо-сайта из `workbench/`, публикует ассеты и запускает сервер: http://localhost:8000/fastleo, вход `admin@example.com` / `password`.

- `workbench/app/Models/User.php` — модель пользователя с `HasFastleoAccess`;
- `workbench/app/Fastleo/` — демо-разделы: «Статьи» (все базовые поля, вкладки, корзина, Policy), «Категории», «Обращения» (только просмотр);
- `workbench/app/Providers/WorkbenchServiceProvider.php` — папка ресурсов демо и регистрация Policy;
- `workbench/database/migrations/`, `factories/`, `seeders/DatabaseSeeder.php` — таблицы и демо-данные;
- `testbench.yaml` — настройки демо-сайта (провайдеры, миграции, сидер, переменные окружения).

Artisan-команды пакета запускаются через Testbench:

```bash
vendor/bin/testbench fastleo:user someone@example.com
vendor/bin/testbench route:list --path=fastleo
```

## Интерфейс

Исходники — `resources/css/fastleo.css` (Tailwind CSS 4) и `resources/js/fastleo.js` (Alpine.js). Собранные файлы `resources/dist/fastleo.{css,js}` **коммитятся**: сайты не собирают ассеты fastleo.

```bash
npm run build   # собрать resources/dist
npm run dev     # пересобирать CSS при изменении шаблонов
```

Tailwind ищет классы только в `resources/views`. После правки шаблонов или исходников выполните `npm run build` и закоммитьте `resources/dist` — CI проверяет, что сборка актуальна.

Иконки Lucide лежат в `resources/icons` (сжатые SVG). Обновление набора: поднять версию `lucide-static` в `package.json`, затем

```bash
npm install
npm run icons
```

## Проверки

```bash
composer docs:build     # пересобрать skill, гайдлайны Boost и docs/llms.txt из docs/
composer check          # всё сразу: Pint, Rector, PHPStan, покрытие типами и строками
composer lint           # pint --test, rector --dry-run, phpstan (уровень max)
composer test           # тесты параллельно, без браузерных
composer test:browser   # браузерные тесты (Playwright)
vendor/bin/pest --filter=имя
vendor/bin/pint         # исправить оформление кода
```

Skill для Claude (`resources/boost/skills/fastleo`), гайдлайны Boost и `docs/llms.txt` собираются из `docs/` командой `composer docs:build` и руками не правятся; тест проверяет, что они свежие. Правьте `docs/`, затем пересоберите.

Требования: PHPStan уровня max без baseline, покрытие строк не ниже 95 %, покрытие типами 100 %.

Тесты (Pest):

- `tests/Feature` — маршруты, команды, поведение через HTTP;
- `tests/Feature/Security` — атакующие сценарии: перебор пароля, CSRF, доступ без прав, заголовки;
- `tests/Unit` — отдельные классы;
- `tests/Browser` — сценарии в браузере, где важен JavaScript;
- `tests/Arch` — правила кода: `declare(strict_types=1)`, нет `dd()`/`dump()`, `env()` только в конфиге, классы `final`.

CI (GitHub Actions) прогоняет тесты на PHP 8.4 и 8.5 с SQLite, MySQL и PostgreSQL. Тест на нестрогое сравнение email запускается только с MySQL.

## Правила кода

- `declare(strict_types=1)` в каждом PHP-файле, типы у всех параметров, свойств и возвращаемых значений.
- PHPDoc на русском у всех классов и методов; у публичного API — короткий пример.
- Тексты интерфейса — только в `resources/lang/ru/*.php`, ссылки — только `route()`.
- Новая возможность — вместе с тестами и разделом в `docs/`.
