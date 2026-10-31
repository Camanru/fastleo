# Настройки сайта

Страница настроек — класс с полями, как ресурс, но без модели: телефон, почта для заявок, часы работы, счётчики, соцсети. Администратор правит их в админке, а сайт читает функцией `fastleo_setting()`.

## Страница настроек

Класс создаётся рядом с ресурсами (по умолчанию `app/Fastleo/`) и наследует `Fastleo\Settings`. Заготовка (суффикс `Settings` добавляется сам; существующий файл без `--force` не перезаписывается):

```bash
php artisan fastleo:make-settings Contacts        # app/Fastleo/ContactsSettings.php
```

```php
// app/Fastleo/ContactsSettings.php
namespace App\Fastleo;

use Fastleo\Fields\{Email, Repeater, Tel, Text, Textarea, Toggle, Url};
use Fastleo\Layout\Section;

final class ContactsSettings extends \Fastleo\Settings
{
    protected static ?string $label = 'Контакты';
    protected static string $icon = 'phone';

    public function fields(): array
    {
        return [
            Section::make('Связь', [
                Tel::make('phone', 'Телефон')->default('+7 900 000-00-00'),
                Email::make('lead_email', 'Почта для заявок')->required(),
                Textarea::make('working_hours', 'Часы работы'),
            ]),
            Text::make('metrika_id', 'Номер счётчика Метрики'),
            Toggle::make('show_promo', 'Показывать акцию'),
            Repeater::make('socials', 'Соцсети')->fields([
                Text::make('title', 'Название')->required(),
                Url::make('url', 'Ссылка')->required(),
            ]),
        ];
    }
}
```

Страница сама появляется в меню (группа «Настройки») и открывается по адресу `/fastleo/settings/contacts`. Поля, правила проверки, подсказки и раскладка (`Section`, `Tabs`, `Grid`) — те же, что у [ресурсов](resources.md) и [полей](fields/README.md).

| Свойство | По умолчанию | Что задаёт |
|---|---|---|
| `$label` | имя класса без «Settings» | Название в меню и заголовке |
| `$icon` | `'settings'` | Иконка Lucide |
| `$group` | «Настройки» | Группа меню |
| `$position` | `0` | Порядок в группе |
| `$slug` | kebab имени класса без «Settings» (`ContactsSettings` → `contacts`) | Адрес страницы и первая часть ключа. Адрес `softnetica` занят встроенной страницей, а при `fastleo.closing.enabled` ещё и адрес `closing` ([закрытие сайта](site-closing.md)) |

Какие поля работают: те, что хранят значение в самой странице — текст, число, список, переключатель, дата, редактор, картинка, файл, галерея, метки, повторитель, пары «ключ — значение», конструктор. **Нельзя**: связи (`BelongsTo`, `HasMany`…), `Slug`, `Password`, `translatable()`, зависимые списки, а также скрытые, `readonly` и `disabled` поля — значения страницы собираются из формы целиком. Ошибка в описании (например, `BelongsTo`) объяснена понятным сообщением.

## Читать настройки на сайте

```php
fastleo_setting('contacts.phone');                       // '+7 900 000-00-00'
fastleo_setting('contacts.lead_email', 'info@site.ru');  // запасное значение
fastleo_setting('contacts.socials', []);                 // список из повторителя
```

Ключ — `страница.поле`. Правила:

1. Есть сохранённое значение — оно и возвращается.
2. Поле очистили (`null` или пустая строка) — возвращается запасное значение из второго аргумента, а без него — `null`.
3. Страницу ещё ни разу не сохраняли — значение по умолчанию поля (`->default(...)`), а при его отсутствии — запасное значение.
4. Такой страницы или поля нет — запасное значение.

В шаблонах:

```blade
<a href="tel:{{ preg_replace('/[^+\d]/', '', fastleo_setting('contacts.phone')) }}">{{ fastleo_setting('contacts.phone') }}</a>
@foreach (fastleo_setting('contacts.socials', []) as $social)
    <a href="{{ $social['url'] }}">{{ $social['title'] }}</a>
@endforeach
```

То же без хелпера: `Fastleo\Fastleo::setting('contacts.phone')`.

Все страницы читаются **одним запросом** и держатся в кеше приложения до следующего сохранения, поэтому сколько бы раз ни вызывали `fastleo_setting()`, лишних запросов к базе нет. Кеш сбрасывается при сохранении страницы в админке, а также командами `php artisan optimize` и `php artisan optimize:clear` (их обычно запускают при деплое): значения, которые поменял сидер, миграция или правка в базе, подхватятся после деплоя. Без деплоя — `php artisan cache:clear`.

## Секреты

Токены и ключи внешних сервисов можно хранить в базе зашифрованными (ключ приложения `APP_KEY`):

```php
public function encrypted(): array
{
    return ['api_key'];
}
```

Сохранённое значение в форму **не выводится**: поле остаётся пустым, и пустое значение при сохранении оставляет прежний секрет (чтобы заменить — введите новый). Так токен не виден редакторам в исходном коде страницы. `fastleo_setting('contacts.api_key')` отдаёт расшифрованное значение. Очистить секрет из формы нельзя. Если функцию нужно выключать из админки, добавьте на страницу галочку (`Toggle::make('api_enabled', 'Отправлять в CRM')`) и проверяйте её в коде сайта перед использованием ключа: `if (fastleo_setting('contacts.api_enabled')) { … }`. Если `APP_KEY` сменили и расшифровать нельзя, вернётся запасное значение, а не ошибка.

## Доступ

```php
public function canAccess(?Authenticatable $user): bool
{
    return $user?->email === 'owner@site.ru';
}
```

Страница, закрытая для администратора, не показывается в меню и отвечает 403. По умолчанию доступна всем администраторам.

## Как хранится

Таблица `fastleo_settings`: одна строка на страницу (`slug`) и JSON со значениями всех полей. В базу попадают только описанные поля; лишние ключи запроса отбрасываются. Поле, скрытое условием `visibleWhen()`, сохраняет прежнее значение. Таблица создаётся обычным `php artisan migrate`.
