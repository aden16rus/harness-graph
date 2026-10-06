<?php
declare(strict_types=1);

namespace Harness\Domain\Entity;

final class Agent
{
    /**
     * @param array<string> $allowedSkills
     * @param array<string> $allowedSubAgentIds
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $role,
        public string $systemPrompt,
        public string $model = 'gpt-4o',
        public float $temperature = 0.2,
        public int $tokenLimit = 8192,
        public array $allowedSkills = [],
        public ?string $llmProfileId = null,
        public array $allowedSubAgentIds = [],
        public string $createdAt = ''
    ) {
        if ($this->createdAt === '') {
            $this->createdAt = gmdate('Y-m-d H:i:s');
        }
    }

    public function allowsSkill(string $skillName): bool
    {
        return in_array('*', $this->allowedSkills, true) || in_array($skillName, $this->allowedSkills, true);
    }

    public function canCallSubAgent(string $childId, string $childRole): bool
    {
        if (empty($this->allowedSubAgentIds) || in_array('*', $this->allowedSubAgentIds, true)) {
            return true;
        }
        return in_array($childId, $this->allowedSubAgentIds, true)
            || in_array(strtolower($childRole), array_map('strtolower', $this->allowedSubAgentIds), true);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'system_prompt' => $this->systemPrompt,
            'model' => $this->model,
            'temperature' => $this->temperature,
            'token_limit' => $this->tokenLimit,
            'allowed_skills' => $this->allowedSkills,
            'llm_profile_id' => $this->llmProfileId,
            'allowed_sub_agent_ids' => $this->allowedSubAgentIds,
            'created_at' => $this->createdAt,
        ];
    }
}
