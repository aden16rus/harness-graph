<?php
declare(strict_types=1);

namespace Harness\Skills;

use Harness\Domain\Entity\Agent;
use Harness\Domain\Repository\AgentRepositoryInterface;
use Harness\Skills\Builtin\CallSubAgentSkill;

final class SkillRegistry
{
    /** @var array<string, SkillInterface> */
    private array $skills = [];

    public function register(SkillInterface $skill): void
    {
        $this->skills[$skill->getName()] = $skill;
    }

    public function get(string $name): ?SkillInterface
    {
        return $this->skills[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->skills[$name]);
    }

    /**
     * @return array<string, SkillInterface>
     */
    public function getAll(): array
    {
        return $this->skills;
    }

    /**
     * Returns OpenAI-compatible tool definitions for allowed skills of an agent
     */
    public function getToolDefinitionsForAgent(
        Agent $agent,
        PermissionPolicy $policy,
        ?AgentRepositoryInterface $agentRepo = null
    ): array {
        $tools = [];
        foreach ($this->skills as $name => $skill) {
            if ($policy->isAllowed($agent, $name)) {
                if ($skill instanceof CallSubAgentSkill && $agentRepo !== null) {
                    $allAgents = $agentRepo->findAll();
                    $roles = [];
                    foreach ($allAgents as $sub) {
                        if ($sub->id === $agent->id) {
                            continue;
                        }
                        if ($agent->canCallSubAgent($sub->id, $sub->role)) {
                            $roles[] = $sub->role;
                            $roles[] = $sub->id;
                        }
                    }
                    if (!empty($roles)) {
                        $skill->setAllowedRoles($roles);
                    }
                }

                $tools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $skill->getName(),
                        'description' => $skill->getDescription(),
                        'parameters' => $skill->getParametersSchema(),
                    ],
                ];
            }
        }
        return $tools;
    }
}
