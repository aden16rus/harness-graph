<?php
declare(strict_types=1);

namespace Harness\Infrastructure\Repository;

use Harness\Domain\Entity\Project;
use Harness\Domain\Repository\ProjectRepositoryInterface;
use PDO;

final readonly class SqliteProjectRepository implements ProjectRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findById(string $id): ?Project
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return $this->mapRow($row);
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM projects ORDER BY created_at DESC');
        $projects = [];
        while ($row = $stmt->fetch()) {
            $projects[] = $this->mapRow($row);
        }
        return $projects;
    }

    public function save(Project $project): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO projects (id, name, workspace_path, stack, default_container, guidelines_file, default_team_id, project_prompt, created_at)
            VALUES (:id, :name, :ws, :stack, :container, :guidelines, :default_team_id, :project_prompt, :created)
            ON CONFLICT(id) DO UPDATE SET
                name = excluded.name,
                workspace_path = excluded.workspace_path,
                stack = excluded.stack,
                default_container = excluded.default_container,
                guidelines_file = excluded.guidelines_file,
                default_team_id = excluded.default_team_id,
                project_prompt = excluded.project_prompt
        ');

        $stmt->execute([
            ':id' => $project->id,
            ':name' => $project->name,
            ':ws' => $project->workspacePath,
            ':stack' => $project->stack,
            ':container' => $project->defaultContainer,
            ':guidelines' => $project->guidelinesFile,
            ':default_team_id' => $project->defaultTeamId,
            ':project_prompt' => $project->projectPrompt,
            ':created' => $project->createdAt,
        ]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM projects WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private function mapRow(array $row): Project
    {
        return new Project(
            id: (string)$row['id'],
            name: (string)$row['name'],
            workspacePath: (string)$row['workspace_path'],
            stack: (string)$row['stack'],
            defaultContainer: $row['default_container'] ? (string)$row['default_container'] : null,
            guidelinesFile: $row['guidelines_file'] ? (string)$row['guidelines_file'] : null,
            defaultTeamId: isset($row['default_team_id']) && $row['default_team_id'] ? (string)$row['default_team_id'] : 'team_core',
            projectPrompt: isset($row['project_prompt']) ? (string)$row['project_prompt'] : '',
            createdAt: (string)$row['created_at']
        );
    }
}
