<?php
declare(strict_types=1);

namespace Harness\Domain\Repository;

use Harness\Domain\Entity\Session;

interface SessionRepositoryInterface
{
    public function findById(string $id): ?Session;
    public function findByProjectId(string $projectId): array;
    public function save(Session $session): void;
}
