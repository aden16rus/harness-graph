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
        return 'Creates or updates the task TODO list and records the expected outcome for the current task. Call at the beginning to plan, and throughout execution to mark stages as in_progress or completed.';
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
                    'description' => 'The list of todo items reflecting current progress. Completed items must remain completed.',
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

        $node = $context->node;
        // Map of previously completed items to protect against accidental wiping/amnesia
        $previouslyCompleted = [];
        if (!empty($node->todos)) {
            foreach ($node->todos as $t) {
                if (($t['status'] ?? '') === 'completed') {
                    $key = $this->normalizeContentKey($t['content'] ?? '');
                    if ($key !== '') {
                        $previouslyCompleted[$key] = $t['content'];
                    }
                }
            }
        }

        $normalizedTodos = [];
        $seenKeys = [];

        foreach ($rawTodos as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = trim((string)($item['content'] ?? ($item['task'] ?? '')));
            if ($content === '') {
                continue;
            }
            $key = $this->normalizeContentKey($content);
            $seenKeys[$key] = true;

            $status = strtolower(trim((string)($item['status'] ?? 'pending')));
            if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) {
                $status = 'pending';
            }

            // Protect previously completed status if model accidentally demoted it
            if ($status !== 'completed' && isset($previouslyCompleted[$key])) {
                $status = 'completed';
            }

            $normalizedTodos[] = [
                'content' => $content,
                'status' => $status,
            ];
        }

        // Preserve previously completed items that the model might have omitted
        foreach ($previouslyCompleted as $key => $origContent) {
            if (!isset($seenKeys[$key])) {
                // Prepend or retain at beginning as completed
                array_unshift($normalizedTodos, [
                    'content' => $origContent,
                    'status' => 'completed',
                ]);
            }
        }

        if (empty($normalizedTodos)) {
            return SkillResult::fail('No valid todo items provided.');
        }

        $completedCount = 0;
        $inProgressCount = 0;
        $pendingCount = 0;

        foreach ($normalizedTodos as $item) {
            if ($item['status'] === 'completed') {
                $completedCount++;
            } elseif ($item['status'] === 'in_progress') {
                $inProgressCount++;
            } else {
                $pendingCount++;
            }
        }

        // Store on node entity
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
            "✓ TODO list updated (%d completed, %d in_progress, %d pending of %d total):
",
            $completedCount,
            $inProgressCount,
            $pendingCount,
            count($normalizedTodos)
        );
        foreach ($normalizedTodos as $t) {
            $marker = ($t['status'] === 'completed') ? '[COMPLETED]' : (($t['status'] === 'in_progress') ? '[IN_PROGRESS]' : '[PENDING]');
            $summary .= "  " . $marker . " " . $t['content'] . "
";
        }
        if ($node->expectedOutcome !== '') {
            $summary .= "Expected outcome: " . $node->expectedOutcome . "
";
        }

        return SkillResult::ok(rtrim($summary), [
            'todos' => $normalizedTodos,
            'expected_outcome' => $node->expectedOutcome,
        ]);
    }

    private function normalizeContentKey(string $content): string
    {
        $normalized = mb_strtolower(trim($content));
        return preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?: '';
    }
}
