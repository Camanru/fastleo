# FAQ: файлы и картинки

Фото и галереи у записей, обрезка, WebP-копии для сайта, водяной знак, документы, форматы, папки, S3/R2, размер загрузки, файловый менеджер и перенос старых файлов. Все вопросы — [список](README.md).

## Картинка у записи

### Как добавить фото к записи?

Добавьте поле `Image` с папкой загрузки и колонку `string` для пути.

```php
// app/Fastleo/DoctorResource.php
use Fastleo\Fields\Image;

Image::make('photo', 'Фото')->directory('doctors'),
```

```php
// миграция
$table->string('photo')->nullable();
```

`photo` — в `$fillable` модели. Если картинок ещё нет на сайте, выполните один раз `php artisan storage:link`. Подробнее — [Image](../fields/image.md).

### Как вывести картинку записи на сайте?

Используйте компонент `<x-fastleo::picture>` или адрес с диска файлов. В колонке лежит путь на диске (`uploads/doctors/ivanov.jpg`), а не готовый адрес.

```blade
{{-- resources/views/doctors/show.blade.php --}}
<x-fastleo::picture :src="$doctor->photo" :alt="$doctor->name" class="doctor-photo" />

{{-- или вручную --}}
@if ($doctor->photo)
    <img src="{{ Storage::disk(config('fastleo.files.disk'))->url($doctor->photo) }}" alt="{{ $doctor->name }}">
@endif
```

Компонент с пустым `src` ничего не выводит, поэтому проверка `@if` не нужна. С уменьшенными копиями — вопрос [про WebP](#как-сделать-уменьшенные-копии-картинок-webp-для-сайта). Подробнее — [Image](../fields/image.md).

### Как дать администратору обрезать фото под нужную пропорцию?

Добавьте полю `crop()` — в форме появится кнопка «Обрезать» с рамкой.

```php
// app/Fastleo/DoctorResource.php
Image::make('photo', 'Фото')->directory('doctors')->crop('3:4'),
```

Обрезанная копия сохраняется рядом (`ivanov-crop.jpg`), оригинал не меняется. Если одной картинке нужны разные пропорции (квадрат в карточке, широкая на странице), не режьте вручную — задайте `ratio` у размеров вариантов: [две пропорции из одной картинки](#как-получить-квадратное-превью-и-широкую-картинку-из-одного-фото). Подробнее — [Image](../fields/image.md).

### Как разрешить только JPG и PNG?

Передайте полю список форматов — из тех, что разрешены как картинки в конфиге.

```php
// app/Fastleo/DoctorResource.php
Image::make('photo', 'Фото')->directory('doctors')->extensions(['jpg', 'jpeg', 'png']),
```

Подробнее — [Image](../fields/image.md).

## Галереи

### Как сделать галерею фото у записи?

Добавьте поле `Gallery`, колонку `json` и каст `array`. Порядок фото администратор меняет перетаскиванием.

```php
// app/Fastleo/ClinicResource.php
use Fastleo\Fields\Gallery;

Gallery::make('photos', 'Фото клиники')->directory('clinic')->max(20)->hideInList(),
```

```php
// миграция
$table->json('photos')->nullable();

// app/Models/Clinic.php
protected function casts(): array
{
    return ['photos' => 'array'];
}
```

```blade
{{-- resources/views/clinic.blade.php --}}
<div class="gallery">
    @foreach ($clinic->photos ?? [] as $photo)
        <x-fastleo::picture :src="$photo" alt="" loading="lazy" />
    @endforeach
</div>
```

Подробнее — [Gallery](../fields/gallery.md).

### Как сделать галерею с подписью к каждому фото?

`Gallery` хранит только пути. Для подписей возьмите `Repeater` с картинкой и текстом.

```php
// app/Fastleo/ProjectResource.php
use Fastleo\Fields\Image;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Text;

Repeater::make('photos', 'Фото с подписями')->fields([
    Image::make('src', 'Фото')->directory('projects')->required(),
    Text::make('caption', 'Подпись'),
])->max(30),
```

```blade
{{-- resources/views/projects/show.blade.php --}}
@foreach ($project->photos ?? [] as $photo)
    <figure>
        <x-fastleo::picture :src="$photo['src'] ?? null" :alt="$photo['caption'] ?? ''" />
        @if (! empty($photo['caption']))
            <figcaption>{{ $photo['caption'] }}</figcaption>
        @endif
    </figure>
@endforeach
```

Колонка `json`, каст `'photos' => 'array'`. Подробнее — [Repeater](../fields/repeater.md).

## Картинки для сайта

### Как сделать уменьшенные копии картинок (WebP) для сайта?

Опишите размеры в конфиге, укажите их у поля через `variants()` и выводите картинку компонентом `<x-fastleo::picture>` с тем же `preset`.

```php
// config/fastleo.php
'images' => [
    'presets' => [
        'card' => ['widths' => [400, 800], 'ratio' => '4:3'],
    ],
],
```

```php
// app/Fastleo/DoctorResource.php
Image::make('photo', 'Фото')->directory('doctors')->variants('card'),
```

```blade
<x-fastleo::picture :src="$doctor->photo" preset="card" :alt="$doctor->name" sizes="(min-width: 768px) 400px, 100vw" />
```

Копии создаются после сохранения записи — в очереди, если она настроена, иначе сразу. Для уже загруженных картинок выполните `php artisan fastleo:images`. Подробнее — [варианты и `<x-fastleo::picture>`](../files.md#картинки-для-сайта-варианты-и-x-fastleopicture).

### Как получить квадратное превью и широкую картинку из одного фото?

Опишите два размера с разными `ratio` и подключите оба к полю.

```php
// config/fastleo.php
'images' => [
    'presets' => [
        'thumb' => ['widths' => [300, 600], 'ratio' => '1:1'],
        'hero' => ['widths' => [800, 1200, 1600], 'ratio' => null],
    ],
],
```

```php
// app/Fastleo/NewsResource.php
Image::make('cover', 'Обложка')->directory('news')->variants('thumb', 'hero'),
```

```blade
{{-- список новостей --}}
<x-fastleo::picture :src="$item->cover" preset="thumb" alt="" sizes="300px" />

{{-- страница новости, первый экран --}}
<x-fastleo::picture :src="$news->cover" preset="hero" :alt="$news->title" loading="eager" fetchpriority="high" />
```

Обрезка по `ratio` идёт по центру картинки. Подробнее — [варианты картинок](../files.md#картинки-для-сайта-варианты-и-x-fastleopicture).

### Как поставить водяной знак на фото?

Загрузите PNG знака в «Файлы», укажите путь в конфиге и включите `watermark` у нужного размера. Знак ставится только на копии, оригинал остаётся чистым.

```php
// config/fastleo.php
'images' => [
    'presets' => [
        'photo' => ['widths' => [480, 1200], 'ratio' => null, 'watermark' => true],
    ],
    'watermark' => [
        'path' => 'uploads/watermark.png',
        'position' => 'bottom-right',
        'opacity' => 50,
        'size' => 20,
    ],
],
```

```bash
php artisan fastleo:images --preset=photo   # поставить знак на уже загруженные фото
```

На сайте выводите фото с `preset="photo"`: ссылка на оригинал без знака остаётся по прямому адресу файла. Подробнее — [водяной знак](../files.md#водяной-знак).

### Я поменял размеры или водяной знак — как обновить копии?

Пересоздайте варианты командой; она работает без очереди.

```bash
php artisan fastleo:images                 # все размеры
php artisan fastleo:images --preset=card   # только один
```

Подробнее — [пересоздать варианты](../files.md#пересоздать-варианты).

### Почему на сайте выводится оригинал, а не WebP?

Копий ещё нет — компонент тогда выводит оригинал. Проверьте по порядку:

1. У поля есть `variants('card')`, а в шаблоне — тот же `preset="card"`.
2. Очередь работает: `php artisan queue:work` запущен (или `QUEUE_CONNECTION=sync`, тогда копии делаются при сохранении).
3. Картинки загружены до того, как вы добавили `variants()`, — выполните `php artisan fastleo:images`.

Подробнее — [варианты картинок](../files.md#картинки-для-сайта-варианты-и-x-fastleopicture), [очередь на хостинге](../installation.md#очередь-на-хостинге-с-одним-cron).

## Документы

### Как прикрепить PDF или прайс к записи?

Добавьте поле `File` с нужными форматами и выведите ссылку на сайте.

```php
// app/Fastleo/ServiceResource.php
use Fastleo\Fields\File;

File::make('price', 'Прайс')->extensions(['pdf', 'xlsx'])->directory('prices'),
```

```php
// миграция
$table->string('price')->nullable();
```

```blade
@if ($service->price)
    <a href="{{ Storage::disk(config('fastleo.files.disk'))->url($service->price) }}" download>Скачать прайс</a>
@endif
```

Подробнее — [File](../fields/file.md).

### Как сделать один прайс или договор на весь сайт?

Положите поле `File` на страницу настроек — тогда таблица не нужна.

```bash
php artisan fastleo:make-settings Documents       # app/Fastleo/DocumentsSettings.php
```

```php
// app/Fastleo/DocumentsSettings.php
namespace App\Fastleo;

use Fastleo\Fields\File;

final class DocumentsSettings extends \Fastleo\Settings
{
    protected static ?string $label = 'Документы';

    public function fields(): array
    {
        return [
            File::make('price', 'Прайс')->extensions(['pdf'])->directory('documents'),
            File::make('offer', 'Договор-оферта')->extensions(['pdf', 'docx'])->directory('documents'),
        ];
    }
}
```

```blade
@if ($price = fastleo_setting('documents.price'))
    <a href="{{ Storage::disk(config('fastleo.files.disk'))->url($price) }}">Прайс-лист</a>
@endif
```

Подробнее — [настройки сайта](../settings.md).

### Как прикрепить к записи несколько документов с названиями?

Возьмите `Repeater` с полями `File` и `Text`.

```php
// app/Fastleo/ServiceResource.php
use Fastleo\Fields\File;
use Fastleo\Fields\Repeater;
use Fastleo\Fields\Text;

Repeater::make('documents', 'Документы')->fields([
    Text::make('title', 'Название')->required(),
    File::make('file', 'Файл')->extensions(['pdf', 'docx', 'xlsx'])->directory('documents')->required(),
])->max(20),
```

```blade
<ul>
    @foreach ($service->documents ?? [] as $document)
        <li><a href="{{ Storage::disk(config('fastleo.files.disk'))->url($document['file']) }}">{{ $document['title'] }}</a></li>
    @endforeach
</ul>
```

Колонка `json`, каст `'documents' => 'array'`. Отдельная таблица файлов с датой и автором — [recipes.md](../recipes.md#файлы-заявки). Подробнее — [Repeater](../fields/repeater.md).

## Форматы, папки и размер

### Как разрешить загружать другие форматы (`dwg`, `rar`, `mov`)?

Допишите их в `files.extensions`. Список задаётся целиком — оставьте в нём и нужные стандартные форматы.

```php
// config/fastleo.php
'files' => [
    'extensions' => [
        'png', 'jpg', 'jpeg', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'txt', 'csv',
        'mp3', 'mp4', 'webm', 'zip',
        'dwg', 'rar', 'mov',
    ],
],
```

Нет файла `config/fastleo.php` — опубликуйте его: `php artisan vendor:publish --tag=fastleo-config`. Подробнее — [настройки файлов](../configuration.md#файлы).

### Почему не загружается SVG, HTML или PHP?

Эти форматы запрещены всегда, даже если вписать их в конфиг: в них может быть скрипт, который выполнится у посетителя. Сохраните картинку в PNG или WebP. Логотип в SVG положите в `public/images` вместе с кодом сайта и выведите обычным `<img src="/images/logo.svg">`. Подробнее — [безопасность файлов](../files.md#безопасность).

### Как разложить файлы по папкам?

Задайте папку у каждого поля через `directory()` — загруженные в поле файлы попадут туда, папка создастся сама.

```php
Image::make('photo', 'Фото')->directory('doctors'),
File::make('license', 'Лицензия')->directory('documents/licenses'),
Gallery::make('photos', 'Фото')->directory('clinic/2026'),
```

Имя папки — строчная латиница, цифры и дефис (`doctor-photos`, не `doctor_photos` и не `Фото`). Выбрать в поле можно файл из любой папки. Подробнее — [Image](../fields/image.md), [имена файлов](../files.md#имена-файлов).

### Как увеличить наибольший размер загружаемого файла?

Поднимите лимиты PHP и веб-сервера, затем при желании задайте свой предел в конфиге (в КБ).

```ini
; php.ini
upload_max_filesize = 64M
post_max_size = 64M
```

```nginx
# nginx, блок server
client_max_body_size 64m;
```

```php
// config/fastleo.php
'files' => [
    'max_size' => 51200, // 50 МБ; null — сколько разрешает PHP
],
```

После правки `php.ini` перезапустите PHP-FPM. Текущие лимиты видны в «Файлах» под кнопками. Большие фото дополнительно ограничены числом пикселей (`files.max_pixels`). Подробнее — [настройки файлов](../configuration.md#файлы).

## Хранилище

### Как хранить файлы в S3 или Cloudflare R2?

Опишите диск в `config/filesystems.php` с публичным `url` и укажите его в `fastleo.files.disk`. Нужен пакет `league/flysystem-aws-s3-v3`.

```bash
composer require league/flysystem-aws-s3-v3 "^3.0"
```

```php
// config/filesystems.php
'disks' => [
    'r2' => [
        'driver' => 's3',
        'key' => env('R2_ACCESS_KEY_ID'),
        'secret' => env('R2_SECRET_ACCESS_KEY'),
        'region' => 'auto',
        'bucket' => env('R2_BUCKET'),
        'endpoint' => env('R2_ENDPOINT'),     // https://<account>.r2.cloudflarestorage.com
        'url' => env('R2_URL'),               // публичный адрес бакета: https://files.site.ru
        'use_path_style_endpoint' => true,
        'throw' => true,
    ],
],
```

```php
// config/fastleo.php
'files' => [
    'disk' => 'r2',
    'root' => 'uploads',
],
```

Для Amazon S3 подойдёт стандартный диск `s3` из `config/filesystems.php` с заполненным `AWS_URL`. Подробнее — [настройка файлов](../files.md#настройка).

### Как перенести уже загруженные файлы с сервера в S3 или R2?

Скопируйте папку диска целиком, сохранив пути: в записях хранится путь относительно диска (`uploads/…`), поэтому колонки менять не нужно.

```bash
# rclone, удалённое хранилище «r2» настроено заранее (rclone config)
rclone copy storage/app/public/uploads r2:my-bucket/uploads
rclone copy storage/app/public/fastleo r2:my-bucket/fastleo   # копии WebP и миниатюры; можно не копировать
```

Затем переключите `files.disk` на новый диск и выполните:

```bash
php artisan cache:clear
php artisan fastleo:images   # если папку fastleo/ не копировали — пересоздаст копии WebP
```

Миниатюры менеджера создадутся сами при первом показе. Подробнее — [S3 и R2](#как-хранить-файлы-в-s3-или-cloudflare-r2), [пересоздать варианты](../files.md#пересоздать-варианты).

### Почему картинки не открываются на сайте (404)?

Для диска `public` нужна ссылка `public/storage`:

```bash
php artisan storage:link
```

Если ссылка есть, проверьте, что `APP_URL` совпадает с адресом сайта: от него строятся адреса файлов. Остальные причины — [troubleshooting.md](troubleshooting.md#что-делать-если-картинки-в-админке-или-на-сайте-не-открываются).

## Файловый менеджер

### Удаляются ли файлы, когда удаляют запись или меняют картинку?

Нет: один файл может стоять в нескольких записях и в тексте статьи. Лишние файлы удаляют в разделе «Файлы» админки — вместе с файлом удаляются и его копии WebP. Подробнее — [что умеет менеджер](../files.md#что-умеет-менеджер).

### Как спрятать служебную папку в файловом менеджере?

Перечислите её имя в `files.hidden` — менеджер не покажет и не даст удалить её по отдельности.

```php
// config/fastleo.php
'files' => [
    'hidden' => ['thumbs', '*.bak'],
],
```

Файлы остаются доступны по прямым адресам. Подробнее — [скрытые папки и файлы](../files.md#скрытые-папки-и-файлы).

### Как закрыть раздел «Файлы» от части администраторов?

Опишите Gate `manageFastleoFiles`. Остальные администраторы по-прежнему смогут загружать и выбирать файлы в полях форм, но не увидят менеджер и не смогут удалять файлы.

```php
// app/Providers/AppServiceProvider.php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('manageFastleoFiles', fn (User $user) => $user->is_owner);
}
```

Подробнее — [настройка файлов](../files.md#настройка), права в целом — [access.md](access.md).

## Перенос и картинки в тексте

### Как перенести картинки со старого сайта или из другой админки?

Положите файлы в корень загрузок (`storage/app/public/uploads`) и приведите значения в колонках к виду `uploads/…` миграцией.

```bash
php artisan make:migration convert_photo_paths
```

```php
// database/migrations/2026_11_01_000200_convert_photo_paths.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // '/storage/uploads/a.jpg' → 'uploads/a.jpg'
        DB::table('doctors')
            ->where('photo', 'like', '/storage/%')
            ->update(['photo' => DB::raw('substr(photo, 10)')]);
    }
};
```

Если в колонке лежит только имя файла (`photo.jpg`) или адрес с доменом — готовые миграции в [переезде с другой админки](../migrating.md#4-картинки-и-файлы); с fastleo 2.x и 3.0 — в [переходе](../upgrade-from-3.md#6-файлы). После переноса выполните `php artisan fastleo:images`, если у полей есть `variants()`.

### Как вставить картинку или ссылку на PDF в текст RichText?

В редакторе нажмите «Вставить/изменить изображение» и значок папки рядом с адресом — откроется файловый менеджер, там можно и загрузить новый файл. Ссылка на PDF — так же через кнопку ссылки и значок папки. Вставка картинки из буфера обмена выключена: сначала загрузите её в менеджер. Подробнее — [RichText](../fields/rich-text.md).

### Как сделать, чтобы картинки в тексте не вылезали за ширину страницы?

Задайте стили для текста в CSS сайта и такие же — для области редактора, чтобы админ видел то же самое.

```css
/* resources/css/app.css */
.content img { max-width: 100%; height: auto; }
```

```php
// config/fastleo.php
'rich_text' => [
    'content_css' => ['/css/app.css'],
    // или только нужные правила:
    'content_style' => 'img { max-width: 100%; height: auto; }',
],
```

Копии WebP к картинкам внутри RichText не создаются: для фото на первом экране используйте отдельное поле `Image` с `variants()`. Подробнее — [RichText](../fields/rich-text.md), [настройки редактора](../configuration.md#чистка-html-редактора).
