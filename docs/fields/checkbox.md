# Checkbox

Галочка «да/нет».

```php
use Fastleo\Fields\Checkbox;

Checkbox::make('is_featured', 'Показывать на главной');
```

**Колонка:** `$table->boolean('is_featured')->default(false)`. **Модель:** каст `'is_featured' => 'boolean'`.

**Проверка:** «да» или «нет». `required()` не заставляет ставить галочку (снятая — тоже значение); для обязательного согласия — `->rules(['accepted'])`.

Снятая галочка сохраняется как `false` (форма отправляет скрытое значение `0`). В списке — значок ✓ или «—».

Тот же смысл в виде переключателя — [Toggle](toggle.md).
