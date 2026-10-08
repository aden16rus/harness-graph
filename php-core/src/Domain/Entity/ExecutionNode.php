<?php
declare(strict_types=1);

namespace Harness\Domain\Entity;

use Harness\Domain\Enum\NodeStatus;

final class ExecutionNode
{
    /**
     * @param array<array{role: string, text: string, timestamp: string, name?: string}> $dialog
     * @param array<array{id: string, name: string, args: array, status: string, output?: string, error?: string, duration_ms?: number}> $toolCalls
     */
    public function __construct(
        public string $id,
        public string $sessionId,
        public ?string $parentNodeId,
        public string $agentId,
        public string $agentName,
        public string $role,
        public NodeStatus $status = NodeStatus::PENDING,
        public int $depth = 0,
        public string $inputPrompt = '',
        public string $outputResult = '',
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $durationMs = 0,
        public int $contextTokens = 0,
        public ?string $activeTool = null,
        public string $startedAt = '',
        public ?string $finishedAt = null,
        public array $dialog = [],
        public array $toolCalls = [],
        public array $todos = [],
        public string $expectedOutcome = ''
    ) {
        if ($this->startedAt === '') {
            $this->startedAt = gmdate('Y-m-d H:i:s');
        }
        if (empty($this->dialog) && $this->inputPrompt !== '') {
            $this->dialog[] = [
                'role' => 'user',
                'text' => $this->inputPrompt,
                'timestamp' => $this->startedAt,
            ];
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'parent_node_id' => $this->parentNodeId,
            'agent_id' => $this->agentId,
            'agent_name' => $this->agentName,
            'role' => $this->role,
            'status' => $this->status->value,
            'depth' => $this->depth,
            'input_prompt' => $this->inputPrompt,
            'output_result' => $this->outputResult,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'context_tokens' => $this->contextTokens,
            'duration_ms' => $this->durationMs,
            'active_tool' => $this->activeTool,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'dialog' => $this->dialog,
            'tool_calls' => $this->toolCalls,
            'todos' => $this->todos,
            'expected_outcome' => $this->expectedOutcome,
        ];
    }
}
