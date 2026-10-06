<?php
declare(strict_types=1);

namespace Harness\Domain\Repository;

use Harness\Domain\Entity\Project;

interface ProjectRepositoryInterface
{
    public function findById(string $id): ?Project;
    public function findAll(): array;
    public function save(Project $project): void;
    public function delete(string $id): void;
}
