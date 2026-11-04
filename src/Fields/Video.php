<?php

declare(strict_types=1);

namespace Fastleo\Fields;

use Closure;
use Fastleo\Support\Html\VideoUrl;
use Fastleo\Support\VideoLinkRule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Override;

/**
 * Видео по ссылке: YouTube, Rutube или VK Видео. Колонка `string` хранит ссылку так, как её вставили; плеер
 * получается из неё при выводе. Чужие сайты и ссылки не на видео поле не принимает.
 *
 * На сайте плеер выводит `<x-fastleo::video :url="$doctor->video" />`. В форме под полем виден плеер сохранённого видео.
 *
 * ```php
 * Video::make('video', 'Видео о враче')->help('Ссылка на YouTube, Rutube или VK Видео');
 * ```
 */
class Video extends Url
{
    /**
     * @return list<mixed>
     */
    #[Override]
    protected function typeRules(): array
    {
        return [...parent::typeRules(), new VideoLinkRule];
    }

    /**
     * На просмотре — плеер; ссылка не на видео или своё оформление `formatUsing()` — как у `Url`.
     */
    #[Override]
    public function display(Model $record): string|Htmlable|null
    {
        $link = parent::display($record);
        $url = $record->getAttribute($this->name);

        if ($this->formatUsing instanceof Closure || VideoUrl::embed($url) === null) {
            return $link;
        }

        return new HtmlString(Blade::render('<x-fastleo::video :url="$url" class="max-w-lg" />', ['url' => $url]));
    }

    /**
     * В ячейке списка — ссылка: плеер в таблице не нужен.
     */
    #[Override]
    public function listDisplay(Model $record): string|Htmlable|null
    {
        return parent::display($record);
    }
}
