# FAQ: Ошибки и эксплуатация

Что делать, когда админка открывается не так, запись не сохраняется, очередь, планировщик или почта не работают, а также обновление, переезд и выкладка на боевой сайт. Все вопросы — [список](README.md).

## Админка открывается не так

### Что делать, если админка открылась без стилей?

Опубликуйте CSS и JS пакета заново:

```bash
php artisan vendor:publish --tag=fastleo-assets --force
```

Чтобы это происходило само после каждого `composer update`, проверьте, что в `composer.json` сайта есть строка:

```json
"post-update-cmd": [
    "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
]
```

Подробнее — [установка](../installation.md#1-пакет), [обновление](../installation.md#обновление).

### Что делать, если картинки в админке или на сайте не открываются?

Создайте ссылку на публичный диск:

```bash
php artisan storage:link
```

Если ссылка есть, а картинки всё равно не открываются:

1. Проверьте `APP_URL` в `.env` — по нему строятся адреса файлов.
2. Посмотрите значение в колонке: должно быть `uploads/…`, без `/storage/` и домена. Старые значения поправьте миграцией — [переезд: картинки и файлы](../migrating.md#4-картинки-и-файлы).

Подробнее — [файлы](../files.md#настройка).

### Что делать, если нового раздела нет в меню?

1. Сбросьте кеш списка разделов:

   ```bash
   php artisan fastleo:clear
   ```

   На боевом сайте после этого снова соберите кеш: `php artisan optimize` (или `php artisan fastleo:cache`).

2. Проверьте, что раздел найден:

   ```bash
   php artisan fastleo:inspect
   ```

3. Если раздела в списке нет — класс должен лежать в `app/Fastleo` (или подпапке), иметь namespace `App\Fastleo` и наследовать `Fastleo\Resource`.
4. Если раздел в списке есть, а пункта меню нет — у модели есть Policy и её `viewAny` возвращает `false` для вас, или ресурс переопределил `inMenu()`.

Подробнее — [кеш списка разделов](../resources.md#кеш-списка-разделов), [права доступа](../authorization.md#с-policy).

### Что делать, если адрес раздела отвечает 404?

Посмотрите настоящий адрес раздела: он берётся из имени модели во множественном числе (`Doctor` → `doctors`, `News` → `news`).

```bash
php artisan fastleo:inspect
```

Нужен другой адрес — задайте его в ресурсе:

```php
protected static ?string $slug = 'doctors';
```

404 на странице одной записи значит, что запись не попадает в выборку `query()` ресурса.

Подробнее — [`query()`](../resources.md#query), [частые ошибки](../claude.md#частые-ошибки).

### Что делать, если вместо страницы ошибка 500?

Найдите текст ошибки — обычно это ошибка описания раздела с подсказкой, что исправить:

```bash
php artisan fastleo:doctor
tail -n 50 storage/logs/laravel.log
```

- На своей машине поставьте `APP_DEBUG=true` — текст и подсказка появятся прямо на странице.
- На боевом сайте `APP_DEBUG` не включайте: откройте в админке «Система» → «Журнал ошибок» (фильтр «Ошибки»).
- Ошибка в одном разделе: `php artisan fastleo:inspect <адрес> --json` покажет её текст в `error` вместо списка полей.

Подробнее — [ошибки в описании](../resources.md#ошибки-в-описании), [журнал ошибок](../pages.md#журнал-ошибок).

### Как читать вывод `fastleo:doctor`?

Каждая строка — `[где] что не так → что сделать`. «Где» — адрес раздела, `site` (настройка сайта) или `registry` (общий список разделов).

```bash
php artisan fastleo:doctor
```

- **Ошибка** — раздел или админка не заработают. Исправьте по подсказке после `→` и запустите снова. Код выхода `1`.
- **Предупреждение** — работает, но не так, как задумано (очередь `sync`, нет `public/storage`, длина колонки). Код выхода `0`.
- «Проблем не найдено.» — всё в порядке.

Проверка планировщика срабатывает только при `APP_ENV=production`, поэтому запускайте doctor и на боевом сайте.

Подробнее — [все проверки `fastleo:doctor`](../claude.md#fastleodoctor).

### Как запускать `fastleo:doctor` автоматически?

Добавьте тест в сайт — doctor будет проверять разделы в CI вместе с остальными тестами:

```php
// tests/Feature/Admin/FastleoDoctorTest.php
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class); // doctor проверяет таблицы — нужны миграции

it('has no fastleo configuration errors', function (): void {
    $this->artisan('fastleo:doctor')->assertSuccessful();
});
```

Тест падает только на ошибках; предупреждения его не роняют.

Подробнее — [проверка описания разделов](../testing.md#проверка-описания-разделов).

## Запись не сохраняется

### Что делать, если doctor пишет «не в $fillable»?

Добавьте имя поля в `$fillable` модели:

```php
// app/Models/Doctor.php
protected $fillable = ['name', 'specialty', 'photo', 'accepts_appointments'];
```

Если поле только показывается и не должно записываться — сделайте его `->readonly()`. `fastleo_admin` в `$fillable` не добавляйте никогда.

Подробнее — [запись в модель](../resources.md#запись-в-модель).

### Что делать, если doctor пишет «хранит массив … нет каста array»?

Добавьте каст `array` колонке. Он нужен полям Gallery, Tags, KeyValue, Repeater, Blocks, Seo, `Select::multiple()` и любому полю с `translatable()`.

```php
// app/Models/Product.php
protected function casts(): array
{
    return [
        'gallery' => 'array',
        'tags' => 'array',
    ];
}
```

Колонка в миграции — `$table->json('gallery')->nullable()`.

Подробнее — [таблица полей](../fields/README.md#типы).

### Что делать, если doctor пишет «Enum требует каст»?

Добавьте каст колонки в ваш enum:

```php
// app/Models/Order.php
use App\Enums\OrderStatus;

protected function casts(): array
{
    return ['status' => OrderStatus::class];
}
```

Подробнее — [Enum](../fields/enum.md).

### Что делать, если при пустом поле ошибка базы «не заполнено обязательное поле»?

Колонка `NOT NULL`, а пустое поле формы пишется как `NULL`. Выберите одно:

- поле обязательное — `->required()`;
- пустое значение пишется нулём или пустой строкой — `->emptyValue(0)` или `->emptyValue('')`;
- колонка может быть пустой — сделайте её `nullable`:

```php
Schema::table('products', function (Blueprint $table) {
    $table->string('subtitle')->nullable()->change();
});
```

`fastleo:doctor` заранее предупреждает о таких колонках («Колонка … не допускает NULL и не имеет значения по умолчанию…»).

Подробнее — [пустое значение](../fields/README.md#пустое-значение), [«пустое» значение в колонке](../migrating.md#5-пустое-значение-в-колонке).

### Что делать, если длинный текст не сохраняется?

- **Ошибка под полем «не больше 255 символов»** у `Text`, `Email`, `Url`: колонка шире 255 — задайте полю её длину.

  ```php
  Text::make('title', 'Заголовок')->maxLength(500), // колонка $table->string('title', 500)
  ```

- **«Не удалось сохранить: значение слишком длинное для своего поля.»** у `Textarea` или `RichText`: в MySQL колонка `text` вмещает 64 КБ. Расширьте её:

  ```php
  Schema::table('articles', function (Blueprint $table) {
      $table->longText('content')->nullable()->change();
  });
  ```

  Или ограничьте поле: `Textarea::make('excerpt', 'Анонс')->maxLength(500)`.

`fastleo:doctor` на MySQL и PostgreSQL сверяет длину поля и колонки.

Подробнее — [Text](../fields/text.md), [Textarea](../fields/textarea.md), [ошибки базы данных](../validation.md#ошибки-базы-данных).

## Очередь, планировщик и почта

### Как включить очередь вместо `sync`?

Без очереди всё работает, но картинки, импорт и письма обрабатываются во время запроса. Включите очередь в базе:

```bash
php artisan make:queue-table   # если таблицы jobs ещё нет
php artisan migrate
```

```dotenv
QUEUE_CONNECTION=database
```

Держите обработчик запущенным через Supervisor:

```ini
; /etc/supervisor/conf.d/site-worker.conf
[program:site-worker]
command=php /var/www/site/artisan queue:work --sleep=3 --tries=1 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3700
stdout_logfile=/var/www/site/storage/logs/worker.log
redirect_stderr=true
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
```

Подробнее — [требования](../installation.md#требования), [очереди в Laravel](https://laravel.com/docs/13.x/queues#supervisor-configuration).

### Что делать, если импорт большого файла обрывается или падает на середине?

1. Включите очередь (вопрос выше): без неё загрузка упирается в `max_execution_time` PHP и таймаут nginx.
2. Поднимите `retry_after` соединения очереди выше часа — импорт идёт до 3600 секунд, а у `database` по умолчанию 90:

   ```dotenv
   DB_QUEUE_RETRY_AFTER=3700
   # для redis: REDIS_QUEUE_RETRY_AFTER=3700
   ```

3. После правки `.env` перезапустите обработчик и пересоберите конфиг:

   ```bash
   php artisan config:cache
   php artisan queue:restart
   ```

`fastleo:doctor` предупреждает: «Очередь выдаёт задачу повторно через 90 с (retry_after), а импорт из файла может идти до 3600 с…».

Подробнее — [большие файлы и очередь](../import-export.md#большие-файлы-и-очередь).

### Как разбирать очередь на хостинге, где есть только cron?

Пусть очередь запускает планировщик. В `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:work --stop-when-empty --max-time=3600 --tries=1 --timeout=3600')
    ->everyMinute()
    ->withoutOverlapping(65)
    ->runInBackground();
```

Нужны `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=3700` и строка `schedule:run` в cron (вопрос ниже).

Подробнее — [очередь на хостинге с одним cron](../installation.md#очередь-на-хостинге-с-одним-cron).

### Что делать, если doctor пишет «Планировщик не запускался…»?

Добавьте в cron пользователя, от которого работает сайт (`crontab -e`):

```bash
* * * * * cd /var/www/site && php artisan schedule:run >> /dev/null 2>&1
```

Проверьте:

```bash
php artisan schedule:list   # задачи, в том числе fastleo:prune
php artisan schedule:run    # запустить вручную
```

Если cron работает, а предупреждение остаётся — кеш приложения на драйвере `array`: метка запуска не сохраняется. Поставьте `CACHE_STORE=database` или `redis`.

Подробнее — [вход: планировщик](../installation.md#5-вход), [состояние сервера](../dashboard.md#serverstatus--состояние-сервера).

### Что делать, если письма не уходят?

1. Проверьте почту в `.env`: на боевом сайте `MAIL_MAILER` не должен быть `log` или `array`.

   ```dotenv
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.example.ru
   MAIL_PORT=465
   MAIL_USERNAME=robot@site.ru
   MAIL_PASSWORD=пароль
   MAIL_FROM_ADDRESS=robot@site.ru
   ```

2. Отправьте пробное письмо:

   ```bash
   php artisan tinker --execute="Illuminate\Support\Facades\Mail::raw('Проверка', fn (\$m) => \$m->to('you@site.ru')->subject('Проверка почты'));"
   ```

3. Письма админки уходят через очередь: проверьте, что обработчик запущен, и посмотрите упавшие задачи:

   ```bash
   php artisan queue:failed
   ```

4. Ошибку отправки ищите в «Журнале ошибок» или `storage/logs/laravel.log`.

Подробнее — [история входов и письма](../auth.md#история-входов), [копия уведомлений на почту](../notifications.md#копия-на-почту).

## Обновление и кеш

### Как обновить пакет?

```bash
composer update camanru/fastleo
php artisan migrate
php artisan vendor:publish --tag=fastleo-assets --force   # если в composer.json нет post-update-cmd
php artisan optimize
php artisan queue:restart                                 # если работает обработчик очереди
php artisan fastleo:doctor
```

Перед обновлением прочитайте раздел новой версии в `CHANGELOG.md`.

Подробнее — [обновление](../installation.md#обновление).

### Когда нужны `fastleo:cache` и `fastleo:clear`?

На боевом сайте после добавления, удаления или переименования раздела:

```bash
php artisan optimize          # включает fastleo:cache
```

Если раздел не появился или админка пишет «Класс ресурса … не найден»:

```bash
php artisan fastleo:clear     # входит в optimize:clear
php artisan fastleo:cache
```

На своей машине кеш не нужен: без него разделы находятся сами.

Подробнее — [кеш списка разделов](../resources.md#кеш-списка-разделов).

## Переезд

### Что делать, если `php artisan migrate` падает: «уже существует»?

Узнайте, какая миграция пакета конфликтует:

```bash
php artisan fastleo:doctor
```

- Таблица или колонка осталась от старой админки — переименуйте её миграцией сайта, которая идёт **раньше** миграций пакета, данные перенесите после.
- Таблица создана этой же версией, а потерялась только запись о миграции — добавьте строку с именем миграции из сообщения doctor в таблицу `migrations`.

Подробнее — [переезд: `migrate` падает](../migrating.md#3-migrate-падает-уже-существует), [миграции при переходе с 3.0](../upgrade-from-3.md#5-миграции-и-перенос-администраторов).

### Как перейти с fastleo 2.x или 3.0?

Это переход на другую админку, а не обновление: разделы описываются заново классами-ресурсами. Порядок:

1. Копия базы и `storage/app/public/uploads`.
2. `composer remove fastleo/fastleo`, удалить старые `config/fastleo.php`, `storage/app/public/fastleo`, `resources/views/vendor/fastleo`.
3. `composer require camanru/fastleo` и `php artisan fastleo:install`.
4. Модель пользователя — трейт `HasFastleoAccess` и интерфейс `FastleoUser` (`fastleo:install` добавляет их сам, если модель обычная).
5. `php artisan migrate`, пути файлов — к виду `uploads/…`.
6. Разделы — по одному ресурсу на модель.
7. `php artisan fastleo:doctor` без ошибок.

Подробнее — [переход с fastleo 2.x и 3.0](../upgrade-from-3.md#переход-по-шагам).

### Как переехать на fastleo с другой админки?

1. Копия базы и файлов.
2. Установка: `composer require camanru/fastleo`, `php artisan fastleo:install`, `php artisan migrate`.
3. Администраторы: `php artisan fastleo:user admin@site.ru` каждому.
4. Таблица сброса пароля, пути картинок, «пустые» значения в колонках — по памятке.
5. Разделы — классами-ресурсами: `php artisan fastleo:make-resource Product --generate`.
6. `php artisan fastleo:doctor` без ошибок, проход по чек-листу.

Старые пароли в своём формате (md5 и т. п.) можно принять и заменить на bcrypt при первом входе — [рецепт](../recipes.md#старые-пароли-при-переезде).

Подробнее — [переезд с другой админки](../migrating.md), [чек-лист](../migrating.md#чек-лист).

## Скорость и базы данных

### Что делать, если список открывается медленно на большой таблице?

1. Добавьте индексы на колонки, по которым сортируют (`sortable()`) и фильтруют:

   ```php
   Schema::table('orders', function (Blueprint $table) {
       $table->index('status');
       $table->index('created_at');
   });
   ```

2. Оставьте `searchable()` одному-двум коротким полям (заголовок, email): поиск по подстроке индексом не ускоряется.
3. Держите `badge()` ресурсов лёгким — он считается на каждой странице админки (индекс по колонке условия).
4. Уменьшите кнопку «Все»: `'list' => ['max_all' => 300]` в `config/fastleo.php`.
5. Большие выгрузки делайте в csv, а не в xlsx.

Подробнее — [большие таблицы](../list.md#большие-таблицы), [экспорт больших таблиц](../import-export.md#большие-таблицы), [`badge()`](../resources.md#badge).

### Что учесть на MySQL и PostgreSQL?

- **Длинные тексты в MySQL:** `text` — до 64 КБ; для RichText берите `longText`.
- **Порядок строк KeyValue:** в PostgreSQL берите `json`, не `jsonb`; в MySQL — `$table->text(…)` с кастом `array` (`json` в MySQL сортирует ключи).
- **enum-колонка MySQL:** варианты `Select` должны совпадать с её значениями — doctor предупредит.
- **Длины колонок** doctor сверяет только на MySQL и PostgreSQL — запускайте его на той же базе, что и боевой сайт.
- **Поиск** не зависит от регистра во всех базах; в MySQL — при обычной `_ci`-сортировке колонки.

Подробнее — [KeyValue](../fields/key-value.md), [RichText](../fields/rich-text.md), [поиск](../list.md#поиск).

## Выкладка

### Что проверить перед выкладкой на боевой сайт?

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan queue:restart
php artisan fastleo:doctor
```

Ещё:

1. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, почта `MAIL_*`, `QUEUE_CONNECTION` не `sync`.
2. Cron с `schedule:run` и запущенный обработчик очереди.
3. Администраторы созданы (`php artisan fastleo:user …`), лучше — с обязательной 2FA.
4. nginx не выполняет PHP в `/storage/`.
5. На дашборде виджет «Состояние сервера» без замечаний.

Подробнее — [что сделать на сайте](../security.md#что-сделать-на-сайте), [Администраторы, права и вход](access.md#что-настроить-на-сервере-перед-запуском-админки).
