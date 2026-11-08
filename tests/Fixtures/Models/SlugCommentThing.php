<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Модель с комментариями колонок (таблицу `slug_comment_things` создаёт тест): подпись поля берётся из комментария.
 */
#[Table('slug_comment_things')]
#[Fillable(['title', 'slug'])]
#[WithoutTimestamps]
final class SlugCommentThing extends Model {}
