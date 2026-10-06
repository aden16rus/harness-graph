<?php
declare(strict_types=1);

namespace Harness\Skills;

use Harness\Domain\Entity\Agent;
use Harness\Domain\Entity\ExecutionNode;
use Harness\Domain\Entity\Project;
use Harness\Domain\Entity\Session;
use Harness\Infrastructure\IPC\JsonRpcClient;

final readonly class SkillExecutionContext
{
    public function __construct(
        public Session $session,
        public ExecutionNode $node,
        public Agent $agent,
        public Project $project,
        public JsonRpcClient $ipcClient,
        public ?object $subAgentManager = null
    ) {}
}
