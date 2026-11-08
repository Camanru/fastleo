<?php

declare(strict_types=1);

namespace Workbench\App\Fastleo\Fields;

use Fastleo\Fields\Text;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Override;

/**
 * Своё поле витрины «Промокод» (создано `fastleo:make-field PromoCode`): наследник Text со своим шаблоном
 * (`workbench/resources/views/fastleo/fields/promo-code.blade.php`), своей проверкой, записью и видом в списке.
 *
 * `PromoCode::make('promo_code', 'Промокод')` — латиница, цифры и дефис до 20 символов; сохраняется заглавными.
 */
final class PromoCode extends Text
{
    protected int $maxLength = 20;

    /**
     * Шаблон поля в форме.
     */
    #[Override]
    public function view(): string
    {
        return 'fastleo.fields.promo-code';
    }

    /**
     * Сохраняется заглавными буквами: «sale-10» → «SALE-10».
     */
    #[Override]
    public function dehydrate(mixed $value): mixed
    {
        $value = parent::dehydrate($value);

        return is_string($value) ? mb_strtoupper($value) : $value;
    }

    /**
     * В списке и на просмотре — моноширинным шрифтом.
     */
    #[Override]
    protected function format(mixed $value): ?Htmlable
    {
        return is_string($value) && $value !== '' ? new HtmlString('<code>'.e($value).'</code>') : null;
    }

    /**
     * Строка до 20 символов: латиница, цифры и дефис.
     *
     * @return list<string>
     */
    #[Override]
    protected function typeRules(): array
    {
        return ['string', 'max:'.$this->maxLength, 'regex:/^[A-Za-z0-9-]+$/'];
    }
}
