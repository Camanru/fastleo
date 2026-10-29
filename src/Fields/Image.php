<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Fields\Concerns\HasImageVariants;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Override;

/**
 * Одна картинка из файлового менеджера: фото врача, обложка статьи. В колонке (`string`) —
 * путь относительно диска, например `uploads/doctors/ivanov.jpg`. В форме — превью,
 * «Загрузить» и «Выбрать из файлов», в списке — миниатюра.
 *
 * ```php
 * Image::make('photo', 'Фото')->directory('doctors')->crop('3:4')->variants('card')->required();
 * ```
 */
class Image extends File
{
    use HasImageVariants;

    /** Пропорция рамки обрезки `ширина:высота` или null — без кнопки «Обрезать». */
    protected ?string $crop = null;

    #[Override]
    public function onlyImages(): bool
    {
        return true;
    }

    /**
     * Кнопка «Обрезать»: рамка в этой пропорции, результат — копия рядом с картинкой
     * (`photo-crop.jpg`), которая и выбирается в поле. Оригинал не меняется.
     *
     * ```php
     * Image::make('photo')->crop('3:4');  // портрет
     * Image::make('cover')->crop('16:9'); // обложка
     * ```
     *
     * @throws InvalidArgumentException пропорция не в виде `ширина:высота`
     */
    public function crop(string $ratio): static
    {
        if (preg_match('/^[1-9][0-9]{0,3}:[1-9][0-9]{0,3}$/', $ratio) !== 1) {
            throw new InvalidArgumentException(__('fastleo::exceptions.image_crop_ratio', ['field' => $this->name, 'ratio' => $ratio], Fastleo::LOCALE));
        }

        $this->crop = $ratio;

        return $this;
    }

    /**
     * Новая картинка записи → варианты для сайта (если заданы `variants()`).
     */
    #[Override]
    public function afterSave(Model $record, mixed $value): void
    {
        if (is_string($value) && ($record->wasRecentlyCreated || $record->wasChanged($this->name))) {
            $this->queueVariants([$value]);
        }
    }

    /**
     * Пропорция обрезки `ширина:высота` или null.
     */
    public function getCrop(): ?string
    {
        return $this->crop;
    }
}
