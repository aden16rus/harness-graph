<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class TodoWriteSkill implements SkillInterface
{
    public function getName(): string
    {
        return 'todo_write';
    }

    public function getDescription(): string
    {
        return 'Creates or updates the task TODO list and records the expected outcome for the current task. Must be called at the very beginning of the task to form the plan, and called throughout execution to mark stages as in_progress or completed so progress can be tracked in real-time.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'expected_outcome' => [
                    'type' => 'string',
                    'description' => 'Clear, specific description of the expected final result or success criteria for this task.',
                ],
                'todos' => [
                    'type' => 'array',
                    'description' => 'The complete list of todo items reflecting current progress.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => [
                                'type' => 'string',
                                'description' => 'Short imperative description of the step/subtask.',
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['pending', 'in_progress', 'completed'],
                                'description' => 'Current status of this step.',
                            ],
                        ],
                        'required' => ['content', 'status'],
                    ],
                ],
            ],
            'required' => ['todos'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $rawTodos = (array)($params['todos'] ?? []);
        $expectedOutcome = trim((string)($params['expected_outcome'] ?? ''));

        if (empty($rawTodos)) {
            return SkillResult::fail('The todos array cannot be empty. Specify at least one step.');
        }

        $normalizedTodos = [];
        $completedCount = 0;
        $inProgressCount = 0;
        $pendingCount = 0;

        foreach ($rawTodos as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = trim((string)($item['content'] ?? ($item['task'] ?? '')));
            if ($content === '') {
                continue;
            }
            $status = strtolower(trim((string)($item['status'] ?? 'pending')));
            if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) {
                $status = 'pending';
            }

            if ($status === 'completed') {
                $completedCount++;
            } elseif ($status === 'in_progress') {
                $inProgressCount++;
            } else {
                $pendingCount++;
            }

            $normalizedTodos[] = [
                'content' => $content,
                'status' => $status,
            ];
        }

        if (empty($normalizedTodos)) {
            return SkillResult::fail('No valid todo items provided.');
        }

        // Store on node entity
        $node = $context->node;
        $node->todos = $normalizedTodos;
        if ($expectedOutcome !== '') {
            $node->expectedOutcome = $expectedOutcome;
        }

        // Publish real-time event to WebSocket clients
        $context->ipcClient->publishEvent('graph.node_todos_updated', $context->session->id, $node->id, [
            'expected_outcome' => $node->expectedOutcome,
            'todos' => $node->todos,
            'completed' => $completedCount,
            'in_progress' => $inProgressCount,
            'total' => count($normalizedTodos),
        ]);

        $summary = sprintf(
            "✓ TODO list updated: %d completed, %d in progress, %d pending (Total: %d).%s",
            $completedCount,
            $inProgressCount,
            $pendingCount,
            count($normalizedTodos),
            $node->expectedOutcome !== '' ? " Expected outcome: " . $node->expectedOutcome : ""
        );

        return SkillResult::ok($summary, [
            'todos' => $normalizedTodos,
            'expected_outcome' => $node->expectedOutcome,
        ]);
    }
}
