# Video

Видео по ссылке: YouTube, Rutube или VK Видео. Вариант [Url](url.md).

```php
use Fastleo\Fields\Video;

Video::make('video', 'Видео о товаре')->help('Ссылка на YouTube, Rutube или VK Видео');
```

**Колонка:** `$table->string('video')->nullable()`. Хранится ссылка так, как её вставил пользователь: `https://youtu.be/dQw4w9WgXcQ`. Плеер получается из ссылки при выводе — чужой код вставки в базу не попадает.

**Проверка:** как у [Url](url.md), и ссылка должна вести на видео поддерживаемого сервиса. Другой сайт, ссылка не на ролик (канал, стена), подделка вроде `youtube.com.evil.ru` — ошибка «Укажите ссылку на видео YouTube, Rutube или VK Видео.».

| Сервис | Принимаются ссылки |
|---|---|
| YouTube | `youtube.com/watch?v=…`, `youtu.be/…`, `youtube.com/shorts/…`, `youtube.com/live/…`, `youtube.com/embed/…`; плеер — `youtube-nocookie.com` (без cookie до нажатия) |
| Rutube | `rutube.ru/video/…`, `rutube.ru/play/embed/…` — ролики с открытым доступом |
| VK Видео | страница ролика `vk.com/video-22822305_456239026` (или `vkvideo.ru/…`) и адрес плеера из кода вставки `https://vk.com/video_ext.php?oid=…&id=…&hash=…`. Для закрытых роликов вставляйте адрес из «Поделиться → Экспортировать» с `hash` |

**В форме:** поле адреса, под ним плеер сохранённого видео. **В списке:** ссылка. **На странице просмотра:** плеер.

## Вывод на сайте

```blade
<x-fastleo::video :url="$product->video" title="Обзор товара" />
```

Адаптивный блок 16:9 с `<iframe loading="lazy">`; стили встроены, CSS сайта не нужен. Атрибуты (`class`, `style`) попадают на обёртку — ширину задавайте ей. Пустая или чужая ссылка — компонент ничего не выводит.

Работает и внутри [Repeater](repeater.md) и [Blocks](../blocks.md): блок «Видео» с одним полем `Video::make('url')` и шаблоном `resources/views/blocks/video.blade.php` из одной строки `<x-fastleo::video :url="$url" />`.

Общие методы — [README](README.md#общие-методы).

← [Все поля](README.md)
