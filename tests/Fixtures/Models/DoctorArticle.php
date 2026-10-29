<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель таблицы articles без связей и кастов — для проверок `fastleo:doctor`.
 */
#[Fillable(['title', 'ghost', 'keywords', 'status', 'category_id', 'tags'])]
#[Table(name: 'articles')]
final class DoctorArticle extends Model {}
