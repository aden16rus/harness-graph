<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Infrastructure\Database\Connection;
use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class MemorySaveSkill implements SkillInterface
{
    public function getName(): string
    {
        return 'memory_save';
    }

    public function getDescription(): string
    {
        return 'Saves or updates persistent memory for this agent on the current project. Use this to remember stable project facts (tech stack, key files, test/run commands, docker environment) so future sessions do not repeat standard exploratory actions. Keep memory concise and role-focused.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'Concise markdown text summarizing essential persistent facts about the project (stack, key files, commands, environment quirks). Keep it compact, structured, and relevant to your role.',
                ],
            ],
            'required' => ['content'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $content = trim((string)($params['content'] ?? ''));
        if ($content === '') {
            return SkillResult::fail('Content parameter cannot be empty');
        }

        try {
            $pdo = Connection::get();
            $now = gmdate('Y-m-d H:i:s');
            $agentId = $context->agent->id;
            $projectId = $context->project->id;
            $memId = 'mem_' . $agentId . '_' . $projectId;

            $stmt = $pdo->prepare('
                INSERT INTO agent_memories (id, agent_id, project_id, content, updated_at)
                VALUES (:id, :aid, :pid, :content, :updated)
                ON CONFLICT(agent_id, project_id) DO UPDATE SET
                    content = excluded.content,
                    updated_at = excluded.updated_at
            ');
            $stmt->execute([
                ':id' => $memId,
                ':aid' => $agentId,
                ':pid' => $projectId,
                ':content' => $content,
                ':updated' => $now,
            ]);

            $context->ipcClient->publishEvent('agent.memory_updated', $context->session->id, $context->node->id, [
                'agent_id' => $agentId,
                'agent_name' => $context->agent->name,
                'project_id' => $projectId,
                'content' => $content,
                'updated_at' => $now,
            ]);

            $context->ipcClient->publishEvent('graph.memory_updated', $context->session->id, $context->node->id, [
                'agent_id' => $agentId,
                'agent_name' => $context->agent->name,
                'project_id' => $projectId,
                'content' => $content,
                'updated_at' => $now,
            ]);

            return SkillResult::ok(
                "Постоянная память саб-агента успешно обновлена (" . strlen($content) . " байт). Данные сохранены для будущих сессий.",
                [
                    'agent_id' => $agentId,
                    'project_id' => $projectId,
                    'bytes' => strlen($content),
                    'updated_at' => $now,
                ]
            );
        } catch (\Throwable $e) {
            return SkillResult::fail("Failed to save persistent memory: " . $e->getMessage());
        }
    }
}