# Права доступа

Кто входит в панель, решает `canAccessFastleo()` модели пользователя ([installation.md](installation.md)). Что пользователь может делать в разделе, решают обычные [Policies Laravel](https://laravel.com/docs/13.x/authorization#creating-policies) моделей сайта. Если пользователей несколько и права у них разные, включите [роли](roles.md): они проверяются раньше метода `can()` и Policy, и действие разрешено, только когда разрешают все три.

## Без Policy — можно всё

Если у модели нет Policy, любой пользователь может смотреть, создавать, менять и удалять её записи.

## С Policy

Если Policy есть, панель спрашивает её на каждом действии и прячет недоступные кнопки:

| Действие в панели | Метод Policy |
|---|---|
| Пункт меню и список | `viewAny(User $user)` |
| Страница просмотра | `view(User $user, Model $record)` |
| Кнопка «Создать», сохранение новой записи | `create(User $user)` |
| Форма редактирования, сохранение | `update(User $user, Model $record)` |
| Удаление (в корзину или навсегда без корзины) | `delete(User $user, Model $record)` |
| Восстановление из корзины | `restore(User $user, Model $record)` |
| Удаление из корзины навсегда | `forceDelete(User $user, Model $record)` |
| Стрелки и перетаскивание ([ручной порядок](resources.md#ручной-порядок)) | `reorder(User $user, Model $record)`; если метода нет — `update` |
| [Экспорт](import-export.md) | `export(User $user)`; если метода нет — `viewAny` |
| [Импорт](import-export.md#импорт): загрузка файла | `import(User $user)`; если метода нет — `viewAny` |
| Импорт: каждая строка | `create(User $user)` / `update(User $user, Model $record)` — как в форме |
| [Действие](actions.md) над записью или выбранными | метод из `$ability` действия (по умолчанию `update`) — на каждую запись |

Корзину (список удалённых записей и их выгрузку) открывает тот же `viewAny`, что и обычный список: данные в ней те же, что были видны до удаления, а опасные действия над ней — `restore` и `forceDelete` — проверяются отдельно. Отдельного права «видеть корзину» нет.

Метода нет в Policy — действие запрещено (так работает Laravel). Запрещённое действие возвращает 403, даже если запрос отправлен в обход кнопок.

Заготовка со всеми способностями, которые спрашивает панель (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`, `reorder`, `import`, `export`; везде `true`), — лучше, чем `make:policy` Laravel:

```bash
php artisan fastleo:make-policy Article           # app/Policies/ArticlePolicy.php (модель App\Models\Article; другая — --model=)
```

Затем впишите условия, например:

```php
// app/Policies/ArticlePolicy.php
final class ArticlePolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Article $article): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, Article $article): bool { return true; }

    // Опубликованную статью удалить нельзя
    public function delete(User $user, Article $article): bool
    {
        return ! $article->is_published;
    }

    public function restore(User $user, Article $article): bool { return true; }
    public function forceDelete(User $user, Article $article): bool { return $user->is_super; }
}
```

Policy находится по правилам Laravel (`App\Policies\ArticlePolicy` для `App\Models\Article`) или регистрируется явно: `Gate::policy(Article::class, ArticlePolicy::class)`. Пользователь в методах Policy — пользователь, вошедший в панель (guard `fastleo`). `Gate::before()` сайта тоже действует.

Если Policy нужна сайту для другого (например, личный кабинет), а в панели разрешено всё — учтите в её методах, что пользователь fastleo тоже проходит через неё: `if ($user->fastleo_admin) return true;`.

## Ограничение записей

Чтобы пользователь видел только часть записей, ограничьте выборку в ресурсе — [`query()`](resources.md#query). Запись вне выборки нельзя открыть, изменить или удалить (404).

## Ограничение без Policy: `can()`

Метод ресурса `can(string $ability, ?Model $record): bool` запрещает действие сверх Policy (и когда Policy нет). Кнопки скрываются, запрос получает 403, массовое действие пропускает запись.

```php
public function can(string $ability, ?Model $record): bool
{
    // Системные записи нельзя удалить
    return $ability !== 'delete' || ! $record?->is_system;
}
```

`$ability` — способность Policy (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`), а также `export`, `import` и `reorder` (`can()` спрашивается про них, даже если такого метода в Policy нет или Policy нет совсем; «Разрешить» при этом по-прежнему решает `viewAny` или `update`); для действий над разделом (список, создание) `$record` равен `null`. Встроенный раздел [«Пользователи»](auth.md#пользователи) так защищает вошедшего и последнего пользователя.

## Проверка перед удалением: `beforeDelete()`

`can()` решает заранее, показывать ли кнопку. Условие, которое нужно проверить в момент удаления (запись только что получила связанные данные, другой пользователь удаляет последнего из группы), ставят в `beforeDelete(Model $record)`: метод вызывается внутри транзакции удаления — из раздела, из корзины, массовым действием и из MCP (`delete-record`). Чтобы оставить запись, бросьте `OperationCancelled` с причиной: пользователь увидит её, а не общий отказ.

```php
use Fastleo\Support\OperationCancelled;

public function beforeDelete(Model $record): void
{
    if ($record->orders()->exists()) {
        throw new OperationCancelled('У клиента есть заказы.');
    }
}
```

## Владелец и `$ownerOnly`

В CRM и других сервисах людей обычно двое видов: **владелец** (видит и меняет всё) и **менеджеры** (работают, но не удаляют, не выгружают базу, не трогают настройки). Роли на `spatie/laravel-permission` ([roles.md](roles.md)) для этого не нужны: скажите панели, кто владелец, в `AppServiceProvider::boot()`:

```php
use Fastleo\Fastleo;

Fastleo::resolveOwnerUsing(fn (User $user) => $user->is_owner);
```

Замыкание получает пользователя, а любой ответ, кроме `true`, значит «не владелец» (значение `1` из колонки без `boolean`-каста тоже не годится: приведите его к `bool` или добавьте каст в модели); исключение внутри замыкания попадает в лог и тоже даёт «не владелец». Ответ на запрос запоминается для объекта пользователя. **С этого момента** файлы, журнал ошибок, очистка кеша, SoftNetica, страницы настроек, свои страницы и раздел «Пользователи» (подпись задаёт `fastleo.users.label`) открыты только владельцу (свои `Gate::define` из таблицы ниже не нужны, а если они есть — решают они), а в списке пользователей появляется столбец «Владелец». Тот же вопрос задаёт код сайта, например Policy: `Fastleo::isOwner()` (вошедший) или `Fastleo::isOwner($user)`. При включённых [ролях](roles.md) владельца определяет роль, а `resolveOwnerUsing()` не действует (`fastleo:doctor` предупредит).

Чтобы закрыть для остальных часть раздела, перечислите это в `$ownerOnly`:

```php
final class LeadResource extends Resource
{
    // менеджер сообщение читает и разбирает кнопками, но не создаёт, не правит, не удаляет и не выгружает
    protected static array $ownerOnly = ['create', 'update', 'delete', 'export'];
}

final class VariantResource extends Resource
{
    // общий список «Варианты» — только владельцу, а таблица вариантов на карточке запчасти открыта
    protected static bool $ownerOnlyIndex = true;
}
```

| Значение | Что закрывается |
|---|---|
| `'view'` | список, просмотр и экспорт |
| `'create'` | создание и импорт |
| `'update'` | изменение, ручной порядок, восстановление из корзины, свои [действия](actions.md) |
| `'delete'` | удаление и удаление из корзины навсегда |
| `'export'`, `'import'`, `'forceDelete'` и другие точные способности | только они: `'export'` закрывает выгрузку, оставляя список |
| `'*'` | раздел целиком: нет пункта меню, адреса отвечают 403, подписи его записей не попадают в поля и фильтры других разделов (в ячейках списка колонка `BelongsTo` по-прежнему показывает название связанной записи) |

`$ownerOnlyIndex = true` закрывает общий список раздела (`/{раздел}`) и всё, что отдаёт его целиком: выгрузку, импорт, фильтры, поиск Ctrl+K, пункт меню и MCP; таблицы этого раздела на карточках других записей и правка остаются открытыми. Так закрывают вспомогательные разделы без пункта меню (запчасти, варианты), чьи общие списки собрали бы всю базу.

Проверка стоит выше Policy и `can()`: даже если Policy сайта сама разрешает `export` или `reorder`, раздел закроет их для не-владельца. Группы считаются так же, как у [ролей](roles.md#как-работает) (`view`: viewAny/view/export; `update`: update/reorder/restore и неизвестная способность действия; `delete`: delete/forceDelete).

Что надо знать:

- **Нет владельца — раздел закрыт всем.** Если задан `$ownerOnly`, а ни ролей, ни `resolveOwnerUsing()` нет, раздел недоступен и настоящему владельцу; `fastleo:doctor` говорит об этом ошибкой. То же без вошедшего пользователя (консоль, очередь, вебхук): владельца нет.
- **Последнего владельца защищает только сайт.** Встроенная защита («нельзя оставить сайт без владельца») работает на ролях; с `resolveOwnerUsing()` панель не знает, где хранится флаг, и не мешает владельцу снять его у других или у себя.
- **Вложенные записи формы** (блоки `HasMany`) пишутся правами родителя и не проверяют `$ownerOnly` дочернего раздела: закрывайте удаление у самих вложенных записей Policy.
- **Загрузка файлов в полях** доступна, пока нет ролей: ограничения `$ownerOnly` на неё не распространяются.

## Раздел только для просмотра

`protected static bool $readonly = true;` — создание, изменение, удаление, ручной порядок и импорт запрещены всем, независимо от Policy ([resources.md](resources.md#только-просмотр)). Работают только [действия](actions.md#права) со своей способностью (`$ability = 'view'` или своё имя метода Policy, например `publish`).

## Связи и вложенные записи

- Варианты связей ([BelongsTo](fields/belongs-to.md), [BelongsToMany](fields/belongs-to-many.md), [MorphTo](fields/morph-to.md)) видит тот, у кого есть `viewAny` раздела (у `MorphTo` — `viewAny` раздела каждого типа; подписи записей видны всем, у кого он есть). Policy связанной модели здесь не спрашивается — ограничьте варианты методом поля `query()`; сохранить запись вне вариантов нельзя.
- Привязать запись к родителю через форму [BelongsTo](fields/belongs-to.md) можно к любому из вариантов поля. Чтобы родитель проверялся по Policy (закрытая или чужая заявка), добавьте полю [`mustAllow('update')`](fields/belongs-to.md#привязка-только-к-тому-что-можно-изменять-mustallow); [связанная таблица](related.md) и быстрое добавление проверяют `update` родителя сами.
- [Связанная таблица](related.md) на странице записи видна при `view` родителя и `viewAny` дочернего раздела; «Добавить» — при `update` родителя и `create` дочернего. Изменяются и удаляются записи таблицы по Policy дочернего раздела.
- Вложенные записи ([HasMany](fields/has-many.md), [HasOne](fields/has-one.md)) создаются, меняются и удаляются в рамках права `update` (или `create`) **родителя**; Policy модели блоков не спрашивается. Блок чужой записи изменить или удалить нельзя — его `id` из запроса игнорируется.

## Сайт с ролями: что закрыть

Права по умолчанию открытые (нет Policy — можно всё), поэтому на сайте с несколькими пользователями закройте всё, что должно быть недоступно. Самый короткий путь — [роли](roles.md): при `fastleo.roles.enabled` (или заданном [`Fastleo::resolveOwnerUsing()`](#владелец-и-owneronly)) раздел «Пользователи», настройки, страницы, файлы, журнал ошибок, очистка кеша и SoftNetica открыты только владельцу, а разделы — по правам роли. Без ролей и резолвера владельца закройте вручную:

| Что | Чем закрыть |
|---|---|
| Свои разделы | Policy модели (`php artisan fastleo:make-policy Lead`) или `can()` ресурса |
| «Пользователи» (`/fastleo/users`) | `Fastleo::resolveOwnerUsing()` (раздел открыт только владельцу) или Policy модели пользователя. Без них любой пользователь открывает раздел и может выдать себе роль или доступ другому; если разделом пользуется только владелец, закройте его Policy на `User` |
| Готовые модули (страницы, новости) | Policy их моделей: код модулей лежит в вашем сайте. Встроенные «Заявки» и «Доступы для сайтов» ([leads.md](leads.md)) закрываются Policy модели `Fastleo\Leads\Lead` и ролями; ключи — только владельцу, когда он задан. Страница настроек «Контакты» закрывается методом `canAccess()` своего класса ([настройки](settings.md)) |
| Журналы ошибок | Gate `viewFastleoLogs` ([журнал ошибок](pages.md#журнал-ошибок)) |
| Файлы | Gate `manageFastleoFiles` ([files.md](files.md)) |
| Очистка кеша | Gate `clearFastleoCache` |
| Страница SoftNetica | Gate `manageFastleoSoftNetica` ([softnetica.md](softnetica.md)) |
| Страницы настроек и свои страницы | метод `canAccess(?Authenticatable $user): bool` страницы (`Settings`, `Page`) |
| Виджеты дашборда (например, «Состояние сервера») | `->visible(fn ($user) => $user->is_owner)` |

```php
// app/Providers/AppServiceProvider.php
Gate::define('viewFastleoLogs', fn (User $user) => $user->role === 'owner');
Gate::define('manageFastleoFiles', fn (User $user) => in_array($user->role, ['owner', 'editor'], true));
Gate::define('clearFastleoCache', fn (User $user) => $user->role === 'owner');
Gate::define('manageFastleoSoftNetica', fn (User $user) => $user->role === 'owner');
```

Роль хранит ваша колонка `users.role` (`Select::make('role')` в наследнике `UserResource`, [auth.md](auth.md#пользователи)). Колонка должна быть в `#[Fillable]` модели, иначе роль не сохранится, и именно поэтому раздел «Пользователи» нужно закрыть: через него роль и меняют.

**Одна Policy на несколько моделей.** Не нужно создавать файл на каждую модель: напишите базовый класс по ролям и привяжите его циклом.

```php
use Illuminate\Support\Facades\Gate;

foreach ([Lead::class, Client::class, Deal::class] as $model) {
    Gate::policy($model, ManagerPolicy::class);
}

foreach ([Product::class, Brand::class] as $model) {
    Gate::policy($model, OwnerOnlyPolicy::class);
}
```

Методы такой Policy принимают запись необязательной: `public function update(User $user, ?Model $record = null): bool`. Панель вызывает их и без записи, например при проверке права на раздел.
