# Url

Адрес страницы. Вариант [Text](text.md).

```php
use Fastleo\Fields\Url;

Url::make('website', 'Сайт')->placeholder('https://');
```

**Колонка:** `$table->string('website')->nullable()`.

**Проверка:** адрес начинается с `http://` или `https://` — ссылки вида `javascript:` не пройдут.

**В списке:** ссылка, открывается в новой вкладке. Если в базе оказалось что-то кроме http(s)-адреса, выводится обычным текстом.
