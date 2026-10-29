<?php

declare(strict_types=1);

namespace Fastleo\Mcp;

use RuntimeException;

/**
 * Инструмент MCP не может выполнить запрос: текст исключения возвращается агенту как ошибка инструмента.
 */
final class ToolFailed extends RuntimeException {}
