<?php
declare(strict_types=1);

namespace Harness\Domain\Repository;

use Harness\Domain\Entity\Agent;

interface AgentRepositoryInterface
{
    public function findById(string $id): ?Agent;
    public function findAll(): array;
    public function save(Agent $agent): void;
    public function delete(string $id): void;
}
