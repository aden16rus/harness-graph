<?php
declare(strict_types=1);

namespace Harness\Infrastructure\Repository;

use Harness\Domain\Entity\Session;
use Harness\Domain\Enum\SessionStatus;
use Harness\Domain\Repository\SessionRepositoryInterface;
use PDO;

final readonly class SqliteSessionRepository implements SessionRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findById(string $id): ?Session
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return new Session(
            id: (string)$row['id'],
            projectId: (string)$row['project_id'],
            teamId: (string)$row['team_id'],
            status: SessionStatus::from((string)$row['status']),
            totalPromptTokens: (int)$row['total_prompt_tokens'],
            totalCompletionTokens: (int)$row['total_completion_tokens'],
            totalDurationMs: (int)$row['total_duration_ms'],
            startedAt: (string)$row['started_at'],
            finishedAt: $row['finished_at'] ? (string)$row['finished_at'] : null
        );
    }

    public function findByProjectId(string $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE project_id = :pid ORDER BY started_at DESC');
        $stmt->execute([':pid' => $projectId]);
        $sessions = [];
        while ($row = $stmt->fetch()) {
            $sessions[] = new Session(
                id: (string)$row['id'],
                projectId: (string)$row['project_id'],
                teamId: (string)$row['team_id'],
                status: SessionStatus::from((string)$row['status']),
                totalPromptTokens: (int)$row['total_prompt_tokens'],
                totalCompletionTokens: (int)$row['total_completion_tokens'],
                totalDurationMs: (int)$row['total_duration_ms'],
                startedAt: (string)$row['started_at'],
                finishedAt: $row['finished_at'] ? (string)$row['finished_at'] : null
            );
        }
        return $sessions;
    }

    public function save(Session $session): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sessions (id, project_id, team_id, status, total_prompt_tokens, total_completion_tokens, total_duration_ms, started_at, finished_at)
            VALUES (:id, :pid, :tid, :status, :p_tokens, :c_tokens, :duration, :started, :finished)
            ON CONFLICT(id) DO UPDATE SET
                status = excluded.status,
                total_prompt_tokens = excluded.total_prompt_tokens,
                total_completion_tokens = excluded.total_completion_tokens,
                total_duration_ms = excluded.total_duration_ms,
                finished_at = excluded.finished_at
        ');

        $stmt->execute([
            ':id' => $session->id,
            ':pid' => $session->projectId,
            ':tid' => $session->teamId,
            ':status' => $session->status->value,
            ':p_tokens' => $session->totalPromptTokens,
            ':c_tokens' => $session->totalCompletionTokens,
            ':duration' => $session->totalDurationMs,
            ':started' => $session->startedAt,
            ':finished' => $session->finishedAt,
        ]);
    }
}
