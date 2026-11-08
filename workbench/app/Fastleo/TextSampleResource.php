<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo;

use Fastleo\Fields\Code;
use Fastleo\Fields\Color;
use Fastleo\Fields\DateTime;
use Fastleo\Fields\Email;
use Fastleo\Fields\Hidden;
use Fastleo\Fields\Number;
use Fastleo\Fields\Password;
use Fastleo\Fields\Range;
use Fastleo\Fields\Secret;
use Fastleo\Fields\Slug;
use Fastleo\Fields\Tel;
use Fastleo\Fields\Text;
use Fastleo\Fields\Textarea;
use Fastleo\Fields\Url;
use Fastleo\Layout\Aside;
use Fastleo\Layout\Grid;
use Fastleo\Layout\Section;
use Fastleo\Layout\Tab;
use Fastleo\Layout\Tabs;
use Fastleo\Resource;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Fastleo\Fields\PromoCode;
use Workbench\App\Models\TextSample;

/**
 * Витрина «Текст и числа»: варианты строковых и числовых полей (длина, подсказки, умолчание, только чтение,
 * отключено, поиск, сортировка, исчезающий столбец, правка в ячейке, формат, вычисляемый столбец), контакты,
 * пароль, секрет, код, скрытое поле и своё поле `PromoCode` (`fastleo:make-field`). Раскладка — вкладки,
 * сетки с `columnSpan()`, секции с описанием, сворачиваемая и свёрнутая, боковая панель.
 */
final class TextSampleResource extends Resource
{
    protected static string $model = TextSample::class;

    protected static ?string $label = 'Текст и числа';

    protected static ?string $singular = 'Пример текста и чисел';

    protected static ?string $description = 'У каждого поля в подсказке — какую возможность оно показывает.';

    protected static string $icon = 'type';

    protected static ?string $group = 'Витрина: поля';

    protected static int $position = 10;

    /**
     * Вычисляемый столбец «Сумма» (`fromQuery()`): количество × цена считается в запросе.
     */
    public function query(Builder $query): Builder
    {
        return $query->select('text_samples.*')->selectRaw('text_samples.quantity * text_samples.price as total');
    }

    /**
     * Поля по вкладкам: строки, числа, контакты, доступ и код.
     */
    public function fields(): array
    {
        return [
            Hidden::make('source')->default('admin')->rules(['in:admin']),
            Tabs::make([
                Tab::make('Строки', [
                    Text::make('title', 'Название')->required()->maxLength(80)->placeholder('Например, Чайник')->searchable()->sortable()
                        ->help('required(), maxLength(80), placeholder(), searchable(), sortable()'),
                    Slug::make('slug', 'Адрес')->from('title')->hideInList()->help('Slug: from(\'title\') — заполняется из названия, уникальный'),
                    Grid::make(3, [
                        Text::make('subtitle', 'Подзаголовок')->default('Новинка')->hideInList()->columnSpan(2)->help('default(\'Новинка\'), columnSpan(2) в Grid(3)'),
                        PromoCode::make('promo_code', 'Промокод')->placeholder('SALE-10')->searchable()->help('Своё поле (fastleo:make-field): свой шаблон, проверка и запись заглавными'),
                    ]),
                    Grid::make(2, [
                        Text::make('sku', 'Артикул')->readonly()->hideOnCreate()->help('readonly(), hideOnCreate() — видно, но не меняется'),
                        Text::make('internal_code', 'Внутренний код')->disabled()->hideInList()->help('disabled() — серое поле, не записывается'),
                    ]),
                    Text::make('note', 'Примечание')->hideWhenEmpty()->help('hideWhenEmpty() — столбец списка пропадает, пока у всех строк пусто'),
                    Textarea::make('description', 'Описание')->rows(3)->maxLength(500)->help('Textarea: rows(3), maxLength(500); в списке скрыто по умолчанию'),
                ]),
                Tab::make('Числа', [
                    Grid::make(3, [
                        Number::make('quantity', 'Количество')->integer()->min(0)->max(100000)->default(0)->sortable()->inlineEditable()
                            ->help('integer(), min(0), max(100000), inlineEditable() — правка в ячейке списка'),
                        Number::make('price', 'Цена')->min(0)->max(99999999.99)->step(0.01)->sortable()
                            ->formatUsing(fn (mixed $value): ?string => is_numeric($value) ? number_format((float) $value, 2, ',', ' ').' ₽' : null)
                            ->help('step(0.01), formatUsing() — вид в списке «1 234,50 ₽»'),
                        Range::make('rating', 'Оценка')->min(0)->max(10)->default(5)->help('Range: ползунок min(0) … max(10), default(5)'),
                    ]),
                    Number::make('total', 'Сумма')->fromQuery()->sortable()
                        ->formatUsing(fn (mixed $value): ?string => is_numeric($value) ? number_format((float) $value, 2, ',', ' ').' ₽' : null)
                        ->help('fromQuery() — значение из query() ресурса, только для показа'),
                ]),
                Tab::make('Контакты', [
                    Section::make('Связь', [
                        Grid::make(2, [
                            Email::make('email', 'Email')->searchable()->help('Email: проверка формата, searchable()'),
                            Tel::make('phone', 'Телефон')->placeholder('+7 900 000-00-00')->hideWhenEmpty()->help('Tel: placeholder(), hideWhenEmpty()'),
                        ]),
                        Url::make('website', 'Сайт')->hideInList()->help('Url: только http(s)-адрес'),
                    ])->description('Section с description(): пояснение под заголовком'),
                ]),
                Tab::make('Доступ и код', [
                    Section::make('Доступ', [
                        Password::make('password', 'Пароль')->confirmed()->help('Password: хеш, confirmed(); пустое — не менять'),
                        Secret::make('api_key', 'Ключ API')->help('Secret: каст encrypted, в форме не показывается; пустое — не менять'),
                    ])->collapsible(),
                    Section::make('Код', [
                        Code::make('snippet', 'HTML-вставка')->rows(5)->help('Code: rows(5), сохраняется без чистки HTML'),
                    ])->collapsed(),
                ]),
            ]),
            Aside::make([
                Color::make('color', 'Цвет')->help('Color: HEX'),
                DateTime::make('created_at', 'Создано')->onlyInList()->sortable(),
            ]),
        ];
    }
}
