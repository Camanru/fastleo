<?php

declare(strict_types=1);

namespace Fastleo\Support;

use RuntimeException;

/**
 * Сайт отменил запись: обработчик модели (`saving`, `creating`, `deleting` и т. п.) вернул false.
 * Бросается внутри транзакции, чтобы откатить уже сделанное (вложенные записи, связи),
 * и показывается админу понятной ошибкой вместо «Сохранено».
 */
final class OperationCancelled extends RuntimeException
{
    //
}
