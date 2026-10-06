<?php
declare(strict_types=1);

namespace Harness\Infrastructure\Repository;

use Harness\Domain\Entity\Team;
use Harness\Domain\Repository\TeamRepositoryInterface;
use PDO;

final readonly class SqliteTeamRepository implements TeamRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findById(string $id): ?Team
    {
        $stmt = $this->pdo->prepare('SELECT * FROM teams WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $members = json_decode((string)$row['member_agent_ids'], true) ?: [];

        return new Team(
            id: (string)$row['id'],
            projectId: (string)$row['project_id'],
            name: (string)$row['name'],
            leadAgentId: (string)$row['lead_agent_id'],
            memberAgentIds: $members,
            createdAt: (string)$row['created_at']
        );
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM teams ORDER BY created_at ASC');
        $teams = [];
        while ($row = $stmt->fetch()) {
            $members = json_decode((string)$row['member_agent_ids'], true) ?: [];
            $teams[] = new Team(
                id: (string)$row['id'],
                projectId: (string)$row['project_id'],
                name: (string)$row['name'],
                leadAgentId: (string)$row['lead_agent_id'],
                memberAgentIds: $members,
                createdAt: (string)$row['created_at']
            );
        }
        return $teams;
    }

    public function findByProjectId(string $projectId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM teams WHERE project_id = :pid ORDER BY created_at ASC');
        $stmt->execute([':pid' => $projectId]);
        $teams = [];
        while ($row = $stmt->fetch()) {
            $members = json_decode((string)$row['member_agent_ids'], true) ?: [];
            $teams[] = new Team(
                id: (string)$row['id'],
                projectId: (string)$row['project_id'],
                name: (string)$row['name'],
                leadAgentId: (string)$row['lead_agent_id'],
                memberAgentIds: $members,
                createdAt: (string)$row['created_at']
            );
        }
        return $teams;
    }

    public function save(Team $team): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO teams (id, project_id, name, lead_agent_id, member_agent_ids, created_at)
            VALUES (:id, :pid, :name, :lead, :members, :created)
            ON CONFLICT(id) DO UPDATE SET
                project_id = excluded.project_id,
                name = excluded.name,
                lead_agent_id = excluded.lead_agent_id,
                member_agent_ids = excluded.member_agent_ids
        ');

        $stmt->execute([
            ':id' => $team->id,
            ':pid' => $team->projectId,
            ':name' => $team->name,
            ':lead' => $team->leadAgentId,
            ':members' => json_encode($team->memberAgentIds),
            ':created' => $team->createdAt,
        ]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM teams WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
