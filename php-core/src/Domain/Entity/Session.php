<?php
declare(strict_types=1);

namespace Harness\Domain\Entity;

use Harness\Domain\Enum\SessionStatus;

final class Session
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $teamId,
        public SessionStatus $status = SessionStatus::PENDING,
        public int $totalPromptTokens = 0,
        public int $totalCompletionTokens = 0,
        public int $totalDurationMs = 0,
        public string $startedAt = '',
        public ?string $finishedAt = null
    ) {
        if ($this->startedAt === '') {
            $this->startedAt = gmdate('Y-m-d H:i:s');
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'team_id' => $this->teamId,
            'status' => $this->status->value,
            'total_prompt_tokens' => $this->totalPromptTokens,
            'total_completion_tokens' => $this->totalCompletionTokens,
            'total_duration_ms' => $this->totalDurationMs,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }
}
