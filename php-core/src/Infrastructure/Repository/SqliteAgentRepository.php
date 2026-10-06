<?php
declare(strict_types=1);

namespace Harness\Infrastructure\Repository;

use Harness\Domain\Entity\Agent;
use Harness\Domain\Repository\AgentRepositoryInterface;
use PDO;

final readonly class SqliteAgentRepository implements AgentRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findById(string $id): ?Agent
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agents WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $skills = json_decode((string)($row['allowed_skills'] ?? '[]'), true) ?: [];
        $subAgents = json_decode((string)($row['allowed_sub_agent_ids'] ?? '[]'), true) ?: [];

        return new Agent(
            id: (string)$row['id'],
            name: (string)$row['name'],
            role: (string)$row['role'],
            systemPrompt: (string)$row['system_prompt'],
            model: (string)$row['model'],
            temperature: (float)$row['temperature'],
            tokenLimit: (int)$row['token_limit'],
            allowedSkills: $skills,
            llmProfileId: isset($row['llm_profile_id']) && $row['llm_profile_id'] ? (string)$row['llm_profile_id'] : null,
            allowedSubAgentIds: $subAgents,
            createdAt: (string)$row['created_at']
        );
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM agents ORDER BY name ASC');
        $agents = [];
        while ($row = $stmt->fetch()) {
            $skills = json_decode((string)($row['allowed_skills'] ?? '[]'), true) ?: [];
            $subAgents = json_decode((string)($row['allowed_sub_agent_ids'] ?? '[]'), true) ?: [];
            $agents[] = new Agent(
                id: (string)$row['id'],
                name: (string)$row['name'],
                role: (string)$row['role'],
                systemPrompt: (string)$row['system_prompt'],
                model: (string)$row['model'],
                temperature: (float)$row['temperature'],
                tokenLimit: (int)$row['token_limit'],
                allowedSkills: $skills,
                llmProfileId: isset($row['llm_profile_id']) && $row['llm_profile_id'] ? (string)$row['llm_profile_id'] : null,
                allowedSubAgentIds: $subAgents,
                createdAt: (string)$row['created_at']
            );
        }
        return $agents;
    }

    public function save(Agent $agent): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO agents (id, name, role, system_prompt, model, temperature, token_limit, allowed_skills, llm_profile_id, allowed_sub_agent_ids, created_at)
            VALUES (:id, :name, :role, :prompt, :model, :temp, :limit, :skills, :profile_id, :sub_agents, :created)
            ON CONFLICT(id) DO UPDATE SET
                name = excluded.name,
                role = excluded.role,
                system_prompt = excluded.system_prompt,
                model = excluded.model,
                temperature = excluded.temperature,
                token_limit = excluded.token_limit,
                allowed_skills = excluded.allowed_skills,
                llm_profile_id = excluded.llm_profile_id,
                allowed_sub_agent_ids = excluded.allowed_sub_agent_ids
        ');

        $stmt->execute([
            ':id' => $agent->id,
            ':name' => $agent->name,
            ':role' => $agent->role,
            ':prompt' => $agent->systemPrompt,
            ':model' => $agent->model,
            ':temp' => $agent->temperature,
            ':limit' => $agent->tokenLimit,
            ':skills' => json_encode($agent->allowedSkills),
            ':profile_id' => $agent->llmProfileId,
            ':sub_agents' => json_encode($agent->allowedSubAgentIds),
            ':created' => $agent->createdAt,
        ]);

        // Sync agent_skills table
        $del = $this->pdo->prepare('DELETE FROM agent_skills WHERE agent_id = :id');
        $del->execute([':id' => $agent->id]);

        $insSkill = $this->pdo->prepare('INSERT OR IGNORE INTO agent_skills (agent_id, skill_name, granted_at) VALUES (:aid, :sname, :created)');
        foreach ($agent->allowedSkills as $skillName) {
            $insSkill->execute([
                ':aid' => $agent->id,
                ':sname' => $skillName,
                ':created' => $agent->createdAt,
            ]);
        }
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM agents WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
