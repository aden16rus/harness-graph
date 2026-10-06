<?php
declare(strict_types=1);

namespace Harness\Orchestrator;

use Harness\Context\ContextManager;
use Harness\Domain\Entity\Agent;
use Harness\Domain\Entity\ExecutionNode;
use Harness\Domain\Entity\Project;
use Harness\Domain\Entity\Session;
use Harness\Domain\Enum\NodeStatus;
use Harness\Domain\Repository\AgentRepositoryInterface;
use Harness\Domain\Repository\ExecutionNodeRepositoryInterface;
use Harness\Domain\Repository\SessionRepositoryInterface;
use Harness\Infrastructure\IPC\JsonRpcClient;
use Harness\Skills\PermissionPolicy;
use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillRegistry;
use RuntimeException;

final class SubAgentManager
{
    public const int MAX_DEPTH = 5;

    private ?ReActEngine $engine = null;

    public function __construct(
        private readonly AgentRepositoryInterface $agentRepo,
        private readonly ExecutionNodeRepositoryInterface $nodeRepo,
        private readonly SessionRepositoryInterface $sessionRepo,
        private readonly JsonRpcClient $ipcClient,
        private readonly ContextManager $contextManager,
        private readonly SkillRegistry $skillRegistry,
        private readonly PermissionPolicy $permissionPolicy
    ) {}

    public function setEngine(ReActEngine $engine): void
    {
        $this->engine = $engine;
    }

    public function spawnChild(
        SkillExecutionContext $parentContext,
        string $childRoleOrId,
        string $taskInstructions,
        array $extraContext = []
    ): ExecutionNode {
        $parentDepth = $parentContext->node->depth;
        if ($parentDepth >= self::MAX_DEPTH) {
            throw new RuntimeException("Maximum sub-agent recursion depth of " . self::MAX_DEPTH . " exceeded.");
        }

        $rawIdentifier = trim($childRoleOrId);
        $normalized = strtolower($rawIdentifier);

        // Intelligent role aliases for common model variants
        $roleMap = [
            'coder' => 'backend',
            'developer' => 'backend',
            'dev' => 'backend',
            'backend_developer' => 'backend',
            'backend_dev' => 'backend',
            'python' => 'backend',
            'programmer' => 'backend',
            'ui' => 'frontend',
            'frontend_developer' => 'frontend',
            'frontend_dev' => 'frontend',
            'web' => 'frontend',
            'tester' => 'qa',
            'test' => 'qa',
            'qa_engineer' => 'qa',
            'reviewer' => 'qa',
            'lead' => 'techlead',
            'architect' => 'techlead',
            'teamlead' => 'techlead',
            'tech_lead' => 'techlead',
            'manager' => 'manager',
            'pm' => 'manager',
        ];

        if (isset($roleMap[$normalized])) {
            $normalized = $roleMap[$normalized];
        }

        // Find child agent by id or by role
        $childAgent = $this->agentRepo->findById($rawIdentifier);
        if ($childAgent === null) {
            $childAgent = $this->agentRepo->findById($normalized);
        }
        if ($childAgent === null) {
            $allAgents = $this->agentRepo->findAll();
            foreach ($allAgents as $a) {
                if (strtolower($a->id) === $normalized || strtolower($a->role) === $normalized) {
                    $childAgent = $a;
                    break;
                }
            }
        }

        if ($childAgent === null) {
            $allAgents = $this->agentRepo->findAll();
            $available = array_map(fn($a) => "{$a->role} (ID: {$a->id})", $allAgents);
            throw new RuntimeException("Sub-agent role or ID '{$childRoleOrId}' not found. Available sub-agents: [" . implode(', ', $available) . "].");
        }

        // Validate delegation permissions: check if parent agent is allowed to invoke this sub-agent
        $parentAgent = $parentContext->agent;
        if ($parentAgent !== null && !$parentAgent->canCallSubAgent($childAgent->id, $childAgent->role)) {
            $allowedList = empty($parentAgent->allowedSubAgentIds) ? 'none' : implode(', ', $parentAgent->allowedSubAgentIds);
            throw new RuntimeException("Agent '{$parentAgent->name}' ({$parentAgent->id}) is not authorized to invoke sub-agent '{$childAgent->name}' ({$childAgent->id}). Allowed sub-agents: [{$allowedList}].");
        }

        $childNodeId = 'node_' . bin2hex(random_bytes(6));
        $now = gmdate('Y-m-d H:i:s');

        $childNode = new ExecutionNode(
            id: $childNodeId,
            sessionId: $parentContext->session->id,
            parentNodeId: $parentContext->node->id,
            agentId: $childAgent->id,
            agentName: $childAgent->name,
            role: $childAgent->role,
            status: NodeStatus::ACTIVE,
            depth: $parentDepth + 1,
            inputPrompt: $taskInstructions,
            startedAt: $now
        );

        $this->nodeRepo->save($childNode);

        // Publish graph.node_created event
        $this->ipcClient->publishEvent('graph.node_created', $parentContext->session->id, $childNode->id, [
            'id' => $childNode->id,
            'parent_node_id' => $childNode->parentNodeId,
            'agent_id' => $childAgent->id,
            'agent_name' => $childAgent->name,
            'role' => $childAgent->role,
            'depth' => $childNode->depth,
            'input_prompt' => $childNode->inputPrompt,
            'status' => $childNode->status->value,
            'started_at' => $childNode->startedAt,
        ]);

        if ($this->engine === null) {
            throw new RuntimeException("ReActEngine is not linked to SubAgentManager");
        }

        $startTime = microtime(true);
        try {
            $childNode = $this->engine->executeNode(
                node: $childNode,
                agent: $childAgent,
                taskPrompt: $taskInstructions,
                project: $parentContext->project,
                session: $parentContext->session
            );

            $childNode->status = NodeStatus::COMPLETED;
            $childNode->finishedAt = gmdate('Y-m-d H:i:s');
            $childNode->durationMs = (int)((microtime(true) - $startTime) * 1000);
            $this->nodeRepo->save($childNode);

            // Publish graph.node_completed event
            $this->ipcClient->publishEvent('graph.node_completed', $parentContext->session->id, $childNode->id, [
                'output_result' => $childNode->outputResult,
                'prompt_tokens' => $childNode->promptTokens,
                'completion_tokens' => $childNode->completionTokens,
                'duration_ms' => $childNode->durationMs,
                'status' => NodeStatus::COMPLETED->value,
            ]);

            return $childNode;
        } catch (\Throwable $e) {
            $childNode->status = NodeStatus::FAILED;
            $childNode->outputResult = "Execution failed: " . $e->getMessage();
            $childNode->finishedAt = gmdate('Y-m-d H:i:s');
            $childNode->durationMs = (int)((microtime(true) - $startTime) * 1000);
            $this->nodeRepo->save($childNode);

            // Publish graph.node_failed event
            $this->ipcClient->publishEvent('graph.node_failed', $parentContext->session->id, $childNode->id, [
                'error' => $e->getMessage(),
                'duration_ms' => $childNode->durationMs,
                'status' => NodeStatus::FAILED->value,
            ]);

            throw $e;
        }
    }
}
