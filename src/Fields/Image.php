<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Fastleo\Fastleo;
use Fastleo\Fields\Concerns\HasImageVariants;
use Fastleo\Files\FileTypes;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Override;

/**
 * Одна картинка из файлового менеджера: фото товара, обложка статьи. В колонке (`string`) —
 * путь относительно диска, например `uploads/products/kettle.jpg`. В форме — превью,
 * «Загрузить» и «Выбрать из файлов», в списке — миниатюра.
 *
 * ```php
 * Image::make('photo', 'Фото')->directory('products')->crop('3:4')->variants('card')->required();
 * ```
 */
class Image extends File
{
    use HasImageVariants;

    /** Пропорция рамки обрезки `ширина:высота` или null — без кнопки «Обрезать». */
    protected ?string $crop = null;

    /** Миниатюра в ячейке заголовка списка вместо отдельного столбца. */
    protected bool $inTitle = false;

    /** Колонка с описанием картинки (alt) или null. */
    protected ?string $altColumn = null;

    /** Подпись поля описания. */
    protected ?string $altLabel = null;

    /** Поле описания, собранное из настроек картинки. */
    private ?Text $altField = null;

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
     * Описание картинки (alt) в парной колонке: поле ввода под картинкой, пишется вместе с ней. Колонка `string`,
     * в `$fillable` модели; доступ (`readonly()`, `hidden()`, `visibleWhen()`…) у описания такой же, как у картинки.
     * Внутри Repeater и Blocks описание — соседний ключ элемента.
     *
     * ```php
     * Image::make('cover', 'Обложка')->alt('cover_alt');
     * ```
     */
    public function alt(string $column, ?string $label = null): static
    {
        $this->altColumn = $column;
        $this->altLabel = $label;
        $this->altField = null;

        return $this;
    }

    /**
     * Поле описания картинки или null, если `alt()` не вызывали. Схема раздела добавляет его после картинки.
     */
    public function altField(): ?Text
    {
        if ($this->altColumn === null) {
            return null;
        }

        if (! $this->altField instanceof Text) {
            $alt = Text::make($this->altColumn, $this->altLabel ?? __('fastleo::resources.image_alt', locale: Fastleo::LOCALE))->maxLength(255)->hideInList();
            $alt->readonly = $this->readonly;
            $alt->disabled = $this->disabled;
            $alt->hidden = $this->hidden;
            $alt->accessModified = $this->accessModified;
            $alt->showInForm = $this->showInForm;
            $alt->showOnCreate = $this->showOnCreate;
            $alt->showOnEdit = $this->showOnEdit;
            $alt->visibleWhen = $this->visibleWhen;
            $this->altField = $alt;
        }

        return $this->altField;
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
     * Миниатюра перед заголовком в первой ячейке списка вместо отдельного столбца «Обложка». Работает для первой
     * такой картинки раздела; в форме и на странице просмотра поле остаётся как было.
     *
     * ```php
     * Image::make('cover', 'Обложка')->inTitle();
     * ```
     */
    public function inTitle(bool $inTitle = true): static
    {
        $this->inTitle = $inTitle;

        return $this;
    }

    /**
     * Картинка показывается в ячейке заголовка, а не отдельным столбцом.
     */
    public function isInTitle(): bool
    {
        return $this->inTitle;
    }

    /**
     * Миниатюра 40×30 для ячейки заголовка; у записи без картинки — пустая рамка того же размера, чтобы заголовки стояли ровно.
     */
    public function titleThumbnail(Model $record): Htmlable
    {
        $path = self::path($record->getAttribute($this->name));

        if ($path === null || ! FileTypes::isImage($path)) {
            return new HtmlString('<span class="inline-block h-[30px] w-10 rounded-md bg-sunken align-middle" data-title-thumb></span>');
        }

        // Рамка лежит под картинкой: если файла нет на диске, битая картинка прячется и остаётся пустая рамка.
        return new HtmlString('<span class="inline-block h-[30px] w-10 overflow-hidden rounded-md bg-sunken align-middle"><img src="'.e(route('fastleo.files.thumb', ['path' => $path])).'" alt="" loading="lazy" class="size-full object-cover" onerror="this.hidden = true" data-title-thumb></span>');
    }

    /**
     * Пропорция обрезки `ширина:высота` или null.
     */
    public function getCrop(): ?string
    {
        return $this->crop;
    }
}
