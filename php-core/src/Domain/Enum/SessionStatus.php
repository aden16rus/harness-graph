<?php
declare(strict_types=1);

namespace Harness\Domain\Enum;

enum SessionStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
