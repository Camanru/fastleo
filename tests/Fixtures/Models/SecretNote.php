<?php

declare(strict_types=1);

namespace Fastleo\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Заметка с секретным полем `pin`, которого нет в `$hidden` модели — таблицу создаёт сам тест (`createSecretNotesTable()`).
 *
 * @property int $id
 * @property string $title
 * @property string|null $pin
 */
#[Fillable(['title', 'pin'])]
final class SecretNote extends Model {}
