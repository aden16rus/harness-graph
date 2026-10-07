<?php
declare(strict_types=1);

namespace Harness\Infrastructure\Repository;

use Harness\Domain\Entity\ExecutionNode;
use Harness\Domain\Enum\NodeStatus;
use Harness\Domain\Repository\ExecutionNodeRepositoryInterface;
use PDO;

final readonly class SqliteExecutionNodeRepository implements ExecutionNodeRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findById(string $id): ?ExecutionNode
    {
        $stmt = $this->pdo->prepare('SELECT * FROM execution_nodes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return $this->mapRow($row);
    }

    public function findBySessionId(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM execution_nodes WHERE session_id = :sid ORDER BY started_at ASC');
        $stmt->execute([':sid' => $sessionId]);
        $nodes = [];
        while ($row = $stmt->fetch()) {
            $nodes[] = $this->mapRow($row);
        }
        return $nodes;
    }

    public function findChildren(string $sessionId, string $parentNodeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM execution_nodes WHERE session_id = :sid AND parent_node_id = :pid ORDER BY started_at ASC');
        $stmt->execute([':sid' => $sessionId, ':pid' => $parentNodeId]);
        $nodes = [];
        while ($row = $stmt->fetch()) {
            $nodes[] = $this->mapRow($row);
        }
        return $nodes;
    }

    public function save(ExecutionNode $node): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO execution_nodes (
                id, session_id, parent_node_id, agent_id, agent_name, role, status, depth,
                input_prompt, output_result, prompt_tokens, completion_tokens, duration_ms,
                active_tool, started_at, finished_at, dialog, tool_calls, todos, expected_outcome
            )
            VALUES (
                :id, :sid, :pid, :aid, :aname, :role, :status, :depth,
                :input, :output, :p_tokens, :c_tokens, :duration,
                :tool, :started, :finished, :dialog, :tool_calls, :todos, :expected_outcome
            )
            ON CONFLICT(id) DO UPDATE SET
                status = excluded.status,
                output_result = excluded.output_result,
                prompt_tokens = excluded.prompt_tokens,
                completion_tokens = excluded.completion_tokens,
                duration_ms = excluded.duration_ms,
                active_tool = excluded.active_tool,
                finished_at = excluded.finished_at,
                dialog = excluded.dialog,
                tool_calls = excluded.tool_calls,
                todos = excluded.todos,
                expected_outcome = excluded.expected_outcome
        ');

        $stmt->execute([
            ':id' => $node->id,
            ':sid' => $node->sessionId,
            ':pid' => $node->parentNodeId,
            ':aid' => $node->agentId,
            ':aname' => $node->agentName,
            ':role' => $node->role,
            ':status' => $node->status->value,
            ':depth' => $node->depth,
            ':input' => $node->inputPrompt,
            ':output' => $node->outputResult,
            ':p_tokens' => $node->promptTokens,
            ':c_tokens' => $node->completionTokens,
            ':duration' => $node->durationMs,
            ':tool' => $node->activeTool,
            ':started' => $node->startedAt,
            ':finished' => $node->finishedAt,
            ':dialog' => json_encode($node->dialog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':tool_calls' => json_encode($node->toolCalls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':todos' => json_encode($node->todos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':expected_outcome' => $node->expectedOutcome,
        ]);
    }

    private function mapRow(array $row): ExecutionNode
    {
        $dialog = [];
        if (!empty($row['dialog'])) {
            $dialog = json_decode((string)$row['dialog'], true) ?: [];
        }

        $toolCalls = [];
        if (!empty($row['tool_calls'])) {
            $toolCalls = json_decode((string)$row['tool_calls'], true) ?: [];
        }

        $todos = [];
        if (!empty($row['todos'])) {
            $todos = json_decode((string)$row['todos'], true) ?: [];
        }

        $expectedOutcome = isset($row['expected_outcome']) ? (string)$row['expected_outcome'] : '';

        return new ExecutionNode(
            id: (string)$row['id'],
            sessionId: (string)$row['session_id'],
            parentNodeId: $row['parent_node_id'] ? (string)$row['parent_node_id'] : null,
            agentId: (string)$row['agent_id'],
            agentName: (string)$row['agent_name'],
            role: (string)$row['role'],
            status: NodeStatus::from((string)$row['status']),
            depth: (int)$row['depth'],
            inputPrompt: (string)$row['input_prompt'],
            outputResult: (string)$row['output_result'],
            promptTokens: (int)$row['prompt_tokens'],
            completionTokens: (int)$row['completion_tokens'],
            durationMs: (int)$row['duration_ms'],
            activeTool: $row['active_tool'] ? (string)$row['active_tool'] : null,
            startedAt: (string)$row['started_at'],
            finishedAt: $row['finished_at'] ? (string)$row['finished_at'] : null,
            dialog: $dialog,
            toolCalls: $toolCalls,
            todos: $todos,
            expectedOutcome: $expectedOutcome
        );
    }
}
