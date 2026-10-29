<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\FeedbackFactory;

/**
 * Обращение с формы сайта.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $message
 */
#[Fillable(['name', 'email', 'message'])]
#[Table(name: 'feedback')]
final class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory;

    /**
     * Фабрика модели.
     */
    protected static function newFactory(): FeedbackFactory
    {
        return FeedbackFactory::new();
    }
}
