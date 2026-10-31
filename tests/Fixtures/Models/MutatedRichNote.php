<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель с текстом, у которого нет своей колонки: значение раскладывает мутатор (таблица создаётся тестом).
 */
#[Table('doctor_rich_notes')]
#[Fillable(['html'])]
#[WithoutTimestamps]
final class MutatedRichNote extends Model
{
    /**
     * Мутатор вместо колонки: пишет текст в колонку `body`.
     */
    protected function setHtmlAttribute(string $value): void
    {
        $this->attributes['body'] = $value;
    }
}
