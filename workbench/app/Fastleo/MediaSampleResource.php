<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\File;
use Fastleo\Fields\Gallery;
use Fastleo\Fields\Image;
use Fastleo\Fields\Text;
use Fastleo\Fields\Video;
use Fastleo\Layout\Section;
use Fastleo\Resource;
use Workbench\App\Models\MediaSample;

/**
 * Витрина «Файлы и медиа»: картинка с обрезкой, вариантами WebP, описанием (alt) и миниатюрой в заголовке списка;
 * картинка с водяным знаком (пресет `photo`); галерея; файл с допустимыми расширениями; видео по ссылке.
 */
final class MediaSampleResource extends Resource
{
    protected static string $model = MediaSample::class;

    protected static ?string $label = 'Файлы и медиа';

    protected static ?string $singular = 'Пример файлов и медиа';

    protected static ?string $description = 'Файлы выбираются в файловом менеджере админки. У каждого поля в подсказке — какую возможность оно показывает.';

    protected static string $icon = 'image';

    protected static ?string $group = 'Витрина: поля';

    protected static int $position = 30;

    /**
     * Поля: картинки, файлы и видео.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Название')->required()->searchable()->sortable(),
            Section::make('Картинки', [
                Image::make('cover', 'Обложка')->directory('showcase')->crop('16:9')->variants('card')->alt('cover_alt', 'Описание обложки')->inTitle()
                    ->help('crop(\'16:9\'), variants(\'card\'), alt(\'cover_alt\'), inTitle() — миниатюра перед названием в списке'),
                Image::make('photo', 'Фото с водяным знаком')->directory('showcase')->variants('photo')->hideInList()
                    ->help('variants(\'photo\') — пресет демо с водяным знаком; без обрезки'),
                Gallery::make('gallery', 'Галерея')->directory('showcase')->max(10)->variants('card')
                    ->help('Gallery: max(10), порядок перетаскиванием, варианты у каждой картинки'),
            ]),
            Section::make('Файлы и видео', [
                File::make('document', 'Документ')->directory('showcase-docs')->extensions(['pdf', 'docx', 'xlsx'])->hideInList()
                    ->help('File: extensions([\'pdf\', \'docx\', \'xlsx\'])'),
                Video::make('video', 'Видео')->hideInList()->help('Video: ссылка на YouTube, Rutube или VK Видео'),
            ]),
        ];
    }
}
