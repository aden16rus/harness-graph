<?php
declare(strict_types=1);

namespace Harness\Domain\Enum;

enum NodeStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case CALLING_TOOL = 'calling_tool';
    case WAITING_HUMAN = 'waiting_human';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
