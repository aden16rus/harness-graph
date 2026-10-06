<?php
declare(strict_types=1);

namespace Harness\Domain\ValueObject;

final readonly class TaskAssignment
{
    public function __construct(
        public string $taskId,
        public string $instructions,
        public ?string $parentNodeId = null,
        public int $maxSteps = 20,
        public int $maxDepth = 5,
        public array $contextData = []
    ) {}
}
