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
| Оформление | из атрибута `style` — только `text-align` (у абзацев, заголовков, цитат, пунктов, ячеек); всё `style` целиком — настройкой `rich_text.styles` |

Удаляется вместе с содержимым: `script`, `style`, `form` и поля ввода, `object`, `embed`, `svg`, `math`, `meta`, `link`. Все обработчики (`onclick`, `onerror`), адреса `javascript:` и `data:`, цвета и шрифты снимаются (если сайт не включил `rich_text.styles`). Прочие теги (`div`, `span`, `h1`, `h5`) убираются, а текст внутри остаётся — кроме тех, что сайт разрешил в `rich_text.tags` (такой тег без текста, например `<div data-lpu="1"></div>`, не считается пустым текстом). Пустой текст (`<p><br></p>`, `<p>&nbsp;</p>`) сохраняется как `NULL`, а у поля с `required()` не проходит проверку. Видео (`iframe`) не получает атрибут `allow`: полный экран работает через `allowfullscreen`, а камера, микрофон и геопозиция плееру недоступны. После ошибки проверки в редактор возвращается уже очищенный HTML.

**Свои теги и атрибуты на весь сайт** — ключ `rich_text` в `config/fastleo.php` (подробнее — [конфигурация](../configuration.md#чистка-html-редактора)):

```php
'rich_text' => [
    'tags' => ['div', 'span'],
    'attributes' => ['class', 'role', 'align', 'data-lpu'],
    'iframe_hosts' => ['https://prodoctorov.ru/widget/'],
    'styles' => true,
],
```

Скрипты, обработчики `on*`, директивы JS-фреймворков (`x-init`, `hx-get`, `v-html`, `ng-click`), `srcdoc` и `javascript:` разрешить нельзя: такая настройка вызывает ошибку, `php artisan fastleo:doctor` её показывает. **Перед первым сохранением старых записей запустите `fastleo:doctor`:** он найдёт записи, у которых чистка вырежет теги, атрибуты и плееры (например, `class` и виджет с чужого сайта), и подскажет, что разрешить.

**Свой профиль у одного поля** — если нужны дополнительные теги только ему:

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
| `emptyValue('')` | Пустой текст сохраняется пустой строкой, а не `null` — для колонки `text NOT NULL` |

**В списке:** начало текста без тегов (100 символов). По умолчанию столбца в списке нет (как у [Textarea](textarea.md)); `showInList()` возвращает его. **На просмотре:** оформленный текст (очищается ещё раз — в базе мог оказаться HTML не из админки).

**Стили сайта в редакторе.** По умолчанию область редактирования без стилей сайта: картинки и шрифты выглядят не так, как у посетителя. `rich_text.content_css` подключает таблицы стилей сайта, `rich_text.content_style` добавляет свои правила — настройки на весь сайт, см. [конфигурацию](../configuration.md#чистка-html-редактора):

```php
'rich_text' => [
    'content_css' => ['/css/app.css'],
    'content_style' => 'img { max-height: 4rem; }',
],
```

**Что изменится в старых записях.** Первое сохранение записи пропускает текст через чистку HTML. Чтобы заранее увидеть, какие записи изменятся и как, выполните `php artisan fastleo:doctor --sanitize-diff` ([подробнее](../claude.md#fastleodoctor)): команда ничего не меняет и показывает «было → станет» по каждой записи. Лишнее разрешите в `fastleo.rich_text` или в `sanitizeUsing()` до сохранения.

Редактор (1,4 МБ) загружается только на формах, где есть RichText. Работает и внутри блоков — [HasMany и HasOne](has-many.md), [Repeater](repeater.md), [Blocks](../blocks.md): редактор запускается в добавленном блоке и сохраняет текст при перетаскивании блока. Для текста без визуального редактора — [Markdown](markdown.md) или [Textarea](textarea.md).
