<?php
declare(strict_types=1);

namespace Harness\Domain\Entity;

final class Project
{
    public function __construct(
        public string $id,
        public string $name,
        public string $workspacePath,
        public string $stack = 'general',
        public ?string $defaultContainer = null,
        public ?string $guidelinesFile = null,
        public string $defaultTeamId = 'team_core',
        public string $projectPrompt = '',
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
            'name' => $this->name,
            'workspace_path' => $this->workspacePath,
            'stack' => $this->stack,
            'default_container' => $this->defaultContainer,
            'guidelines_file' => $this->guidelinesFile,
            'default_team_id' => $this->defaultTeamId,
            'project_prompt' => $this->projectPrompt,
            'created_at' => $this->createdAt,
        ];
    }
}
