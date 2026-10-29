# RichText

Текст с оформлением в визуальном редакторе [HugeRTE](https://github.com/hugerte/hugerte) (MIT-форк TinyMCE 6): заголовки, жирный и курсив, выравнивание, списки, ссылки, картинки из [файлового менеджера](../files.md), таблицы, цитаты, видео. Редактор на русском, в тёмной теме — тёмный. Ctrl/Cmd+S внутри редактора сохраняет запись.

```php
use Fastleo\Fields\RichText;

RichText::make('content', 'Текст')->height(500);
```

**Колонка:** `$table->longText('content')->nullable()` (`text` в MySQL — до 64 КБ, для статей с таблицами мало).

**Чистка HTML.** При сохранении HTML очищается (symfony/html-sanitizer), поэтому на сайте его можно выводить как есть:

```blade
<div class="prose">{!! $article->content !!}</div>
```

Остаётся:

| Что | Теги |
|---|---|
| Текст | `p`, `br`, `h2`, `h3`, `h4`, `strong`, `b`, `em`, `i`, `u`, `s`, `sub`, `sup`, `blockquote`, `hr`, `pre`, `code` |
| Списки | `ul`, `ol` (`start`), `li` |
| Ссылки | `a` (`href` — http, https, mailto, tel или адрес на сайте; `title`, `target`), всем ссылкам добавляется `rel="noopener"` |
| Картинки | `img` (`src` — http, https или адрес на сайте; `alt`, `title`, `width`, `height`, `loading`), `figure`, `figcaption` |
| Таблицы | `table`, `caption`, `thead`, `tbody`, `tfoot`, `tr`, `th`, `td` (`colspan`, `rowspan`) |
| Видео | `iframe` только с плеерами по https: `www.youtube.com/embed/`, `www.youtube-nocookie.com/embed/`, `rutube.ru/play/embed/`, `vk.com/video_ext.php`, `vkvideo.ru/video_ext.php` |
| Оформление | из атрибута `style` — только `text-align` (у абзацев, заголовков, цитат, пунктов, ячеек) |

Удаляется вместе с содержимым: `script`, `style`, `form` и поля ввода, `object`, `embed`, `svg`, `math`, `meta`, `link`. Все обработчики (`onclick`, `onerror`), адреса `javascript:` и `data:`, цвета и шрифты снимаются. Прочие теги (`div`, `span`, `h1`, `h5`) убираются, а текст внутри остаётся. Пустой текст (`<p><br></p>`, `<p>&nbsp;</p>`) сохраняется как `NULL`, а у поля с `required()` не проходит проверку. Видео (`iframe`) не получает атрибут `allow`: полный экран работает через `allowfullscreen`, а камера, микрофон и геопозиция плееру недоступны. После ошибки проверки в редактор возвращается уже очищенный HTML.

**Свой профиль** — если сайту нужны дополнительные теги:

```php
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

RichText::make('content')
    ->sanitizeUsing(fn (HtmlSanitizerConfig $config) => $config->allowElement('span', ['class']));
```

Для HTML из других источников (импорт, API) — тот же профиль: `Fastleo\Support\HtmlSanitizer::clean($html)`.

**Картинки и файлы.** Кнопка «Изображение» → значок папки открывает окно менеджера; выбранная картинка вставляется адресом от корня сайта (`/storage/uploads/...`), поэтому текст не привязан к домену. В диалоге ссылки тот же значок выбирает любой файл. Вставка картинок из буфера обмена (`data:`) выключена — загрузите картинку в менеджер.

**Проверка:** строка до 1 000 000 символов.

| Метод | Что делает |
|---|---|
| `height(500)` | Высота редактора в пикселях (по умолчанию 400, не меньше 150) |
| `sanitizeUsing(fn ($config) => ...)` | Дополнить профиль чистки HTML |

**В списке:** начало текста без тегов (100 символов). По умолчанию поле видно в списке — обычно ему нужен `hideInList()`. **На просмотре:** оформленный текст (очищается ещё раз — в базе мог оказаться HTML не из админки).

Редактор (1,4 МБ) загружается только на формах, где есть RichText. Работает и внутри блоков — [HasMany и HasOne](has-many.md), [Repeater](repeater.md), [Blocks](../blocks.md): редактор запускается в добавленном блоке и сохраняет текст при перетаскивании блока. Для текста без визуального редактора — [Markdown](markdown.md) или [Textarea](textarea.md).
