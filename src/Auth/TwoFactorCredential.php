<?php

declare(strict_types=1);

namespace Fastleo\Auth;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * Двухфакторные данные администратора: секрет (шифруется), хеши резервных кодов, шаг последнего принятого кода.
 * Пока `confirmed_at` пуст, защита не включена: администратор настраивает приложение.
 *
 * @property int $id
 * @property string $user_id
 * @property string $secret
 * @property list<string>|null $recovery_codes
 * @property int|null $last_step
 * @property CarbonInterface|null $confirmed_at
 */
#[Table('fastleo_two_factor')]
#[Unguarded]
#[Hidden(['secret', 'recovery_codes'])]
final class TwoFactorCredential extends Model
{
    /**
     * Касты атрибутов.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_codes' => 'array',
            'last_step' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }
}
