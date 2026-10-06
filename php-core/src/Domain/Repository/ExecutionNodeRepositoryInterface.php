<?php
declare(strict_types=1);

namespace Harness\Domain\Repository;

use Harness\Domain\Entity\ExecutionNode;

interface ExecutionNodeRepositoryInterface
{
    public function findById(string $id): ?ExecutionNode;
    public function findBySessionId(string $sessionId): array;
    public function findChildren(string $sessionId, string $parentNodeId): array;
    public function save(ExecutionNode $node): void;
}
