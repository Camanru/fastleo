<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\ListUx;

use Fastleo\Actions\Action;
use Fastleo\Fields\BelongsTo;
use Fastleo\Fields\Enum;
use Fastleo\Fields\Text;
use Fastleo\Filters\SelectFilter;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Enums\ArticleStatus;
use Workbench\App\Models\Article;

/**
 * Статьи с фильтрами по кнопке, свёрнутой панелью, главной кнопкой строки и хуками страницы записи.
 */
final class ArticleResource extends Resource
{
    protected static string $model = Article::class;

    protected static ?string $slug = 'articles';

    protected static bool $filtersApplyOnChange = false;

    protected static bool $filtersCollapsed = true;

    /** Ключ главного действия строки: подмена проверяет, что несуществующее действие не рисуется. */
    public static string $primaryKey = 'take-article';

    /**
     * Название, статус и рубрика: привязать можно только к рубрике, которую разрешено изменять.
     */
    public function fields(): array
    {
        return [
            Text::make('title', 'Заголовок')->required(),
            Enum::make('status', 'Статус')->enum(ArticleStatus::class)->required(),
            BelongsTo::make('category_id', 'Рубрика')->mustAllow('update'),
        ];
    }

    /**
     * Фильтр по статусу.
     */
    public function filters(): array
    {
        return [SelectFilter::make('status')];
    }

    /**
     * @return list<Action>
     */
    public function actions(): array
    {
        return [new TakeArticle()];
    }

    /**
     * Главная кнопка: подпись по записи.
     */
    public function primaryRowAction(Model $record): array
    {
        return ['key' => self::$primaryKey, 'label' => 'Взять «'.$record->getAttribute('title').'»', 'primary' => true];
    }

    /**
     * Карточка над формой.
     */
    public function summaryView(): string
    {
        return 'listux::summary';
    }

    /**
     * Кнопка в шапке страницы записи.
     */
    public function addButtons(Model $record): array
    {
        return [['label' => 'Добавить соседнюю', 'url' => route('fastleo.resources.create', 'articles')]];
    }

    /**
     * Своё предупреждение об удалении.
     */
    public function deleteWarning(Model $record): string
    {
        return 'Удалить «'.$record->getAttribute('title').'» вместе со всем?';
    }
}
