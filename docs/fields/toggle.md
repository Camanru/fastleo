# Toggle

Переключатель «вкл/выкл». Работает как [Checkbox](checkbox.md), выглядит как тумблер.

```php
use Fastleo\Fields\Toggle;

Toggle::make('is_active', 'Показывать на сайте')->default(true);
```

**Колонка:** `$table->boolean('is_active')->default(true)`. **Модель:** каст `'is_active' => 'boolean'`.

**Проверка:** «да» или «нет». Выключенный переключатель сохраняется как `false`. В списке — значок ✓ или «—».

Удобно класть в боковую панель формы — `Aside::make([Toggle::make('is_active')])` ([layout.md](../layout.md)).
