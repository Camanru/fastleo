<?php

declare(strict_types=1);

namespace Fastleo\Leads;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Заявка: с формы сайта (`POST /leads`) или с другого сайта по API (`POST /api/leads`). Статус и отметку «просмотрено»
 * ставит код пакета и панель, посетитель присылает только имя, телефон, почту и сообщение.
 *
 * @property int $id
 * @property string $name
 * @property string $phone
 * @property string|null $email
 * @property string|null $message
 * @property string|null $source
 * @property string|null $site
 * @property string|null $form
 * @property LeadStatus $status
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'phone', 'email', 'message', 'source', 'site', 'form', 'status'])]
#[Table(name: 'fastleo_leads')]
final class Lead extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'read_at' => 'datetime',
        ];
    }
}
