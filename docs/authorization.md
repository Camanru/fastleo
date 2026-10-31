# Права доступа

Кто входит в админку, решает `canAccessFastleo()` модели пользователя ([installation.md](installation.md)). Что администратор может делать в разделе, решают обычные [Policies Laravel](https://laravel.com/docs/13.x/authorization#creating-policies) моделей сайта.

## Без Policy — можно всё

Если у модели нет Policy, любой администратор может смотреть, создавать, менять и удалять её записи.

## С Policy

Если Policy есть, админка спрашивает её на каждом действии и прячет недоступные кнопки:

| Действие в админке | Метод Policy |
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

Метода нет в Policy — действие запрещено (так работает Laravel). Запрещённое действие возвращает 403, даже если запрос отправлен в обход кнопок.

Заготовка со всеми способностями, которые спрашивает админка (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`, `reorder`, `import`, `export`; везде `true`), — лучше, чем `make:policy` Laravel:

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

Policy находится по правилам Laravel (`App\Policies\ArticlePolicy` для `App\Models\Article`) или регистрируется явно: `Gate::policy(Article::class, ArticlePolicy::class)`. Пользователь в методах Policy — администратор, вошедший в админку (guard `fastleo`). `Gate::before()` сайта тоже действует.

Если Policy нужна сайту для другого (например, личный кабинет), а в админке разрешено всё — учтите в её методах, что админ fastleo тоже проходит через неё: `if ($user->fastleo_admin) return true;`.

## Ограничение записей

Чтобы администратор видел только часть записей, ограничьте выборку в ресурсе — [`query()`](resources.md#query). Запись вне выборки нельзя открыть, изменить или удалить (404).

## Ограничение без Policy: `can()`

Метод ресурса `can(string $ability, ?Model $record): bool` запрещает действие сверх Policy (и когда Policy нет). Кнопки скрываются, запрос получает 403, массовое действие пропускает запись.

```php
public function can(string $ability, ?Model $record): bool
{
    // Системные записи нельзя удалить
    return $ability !== 'delete' || ! $record?->is_system;
}
```

`$ability` — способность Policy (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`), а также `export`, `import` и `reorder` (`can()` спрашивается про них, даже если такого метода в Policy нет или Policy нет совсем; «Разрешить» при этом по-прежнему решает `viewAny` или `update`); для действий над разделом (список, создание) `$record` равен `null`. Встроенный раздел [«Администраторы»](auth.md#администраторы) так защищает вошедшего и последнего администратора.

## Раздел только для просмотра

`protected static bool $readonly = true;` — создание, изменение, удаление, ручной порядок и импорт запрещены всем, независимо от Policy ([resources.md](resources.md#только-просмотр)). Работают только [действия](actions.md#права) со своей способностью (`$ability = 'view'` или своё имя метода Policy, например `publish`).

## Связи и вложенные записи

- Варианты связей ([BelongsTo](fields/belongs-to.md), [BelongsToMany](fields/belongs-to-many.md)) видит тот, у кого есть `viewAny` раздела. Policy связанной модели здесь не спрашивается — ограничьте варианты методом поля `query()`; сохранить запись вне вариантов нельзя.
- [Связанная таблица](related.md) на странице записи видна при `view` родителя и `viewAny` дочернего раздела; «Добавить» — при `update` родителя и `create` дочернего. Изменяются и удаляются записи таблицы по Policy дочернего раздела.
- Вложенные записи ([HasMany](fields/has-many.md), [HasOne](fields/has-one.md)) создаются, меняются и удаляются в рамках права `update` (или `create`) **родителя**; Policy модели блоков не спрашивается. Блок чужой записи изменить или удалить нельзя — его `id` из запроса игнорируется.
