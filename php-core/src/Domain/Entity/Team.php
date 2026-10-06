<?php
declare(strict_types=1);

namespace Harness\Domain\Entity;

final class Team
{
    /**
     * @param array<string> $memberAgentIds
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $name,
        public string $leadAgentId,
        public array $memberAgentIds = [],
        public string $createdAt = ''
    ) {
        if ($this->createdAt === '') {
            $this->createdAt = gmdate('Y-m-d H:i:s');
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'name' => $this->name,
            'lead_agent_id' => $this->leadAgentId,
            'member_agent_ids' => $this->memberAgentIds,
            'created_at' => $this->createdAt,
        ];
    }
}
