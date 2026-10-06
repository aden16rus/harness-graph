<?php
declare(strict_types=1);

namespace Harness\Domain\Repository;

use Harness\Domain\Entity\Team;

interface TeamRepositoryInterface
{
    public function findById(string $id): ?Team;
    public function findAll(): array;
    public function findByProjectId(string $projectId): array;
    public function save(Team $team): void;
    public function delete(string $id): void;
}
