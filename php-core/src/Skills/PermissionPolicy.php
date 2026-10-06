<?php
declare(strict_types=1);

namespace Harness\Skills;

use Harness\Domain\Entity\Agent;

final class PermissionPolicy
{
    public function isAllowed(Agent $agent, string $skillName): bool
    {
        return $agent->allowsSkill($skillName);
    }

    public function assertAllowed(Agent $agent, string $skillName): void
    {
        if (!$this->isAllowed($agent, $skillName)) {
            throw new \DomainException(
                sprintf("Permission denied: Agent '%s' (%s) is not allowed to execute skill '%s'. Allowed skills: [%s]",
                    $agent->name,
                    $agent->role,
                    $skillName,
                    implode(', ', $agent->allowedSkills)
                )
            );
        }
    }
}
