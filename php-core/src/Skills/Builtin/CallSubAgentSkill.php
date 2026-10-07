<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class CallSubAgentSkill implements SkillInterface
{
    /** @var array<string> */
    private array $allowedRoles = ['backend', 'frontend', 'techlead', 'qa'];

    /**
     * @param array<string> $roles
     */
    public function setAllowedRoles(array $roles): void
    {
        $this->allowedRoles = $roles;
    }

    public function getName(): string
    {
        return 'call_sub_agent';
    }

    public function getDescription(): string
    {
        return 'Delegates an isolated, stateless sub-task to a specialized child sub-agent. Each call starts with a clean context and has NO memory of prior calls.';
    }

    public function getParametersSchema(): array
    {
        $rolesEnum = !empty($this->allowedRoles) ? array_values(array_unique($this->allowedRoles)) : ['backend', 'frontend', 'techlead', 'qa'];

        return [
            'type' => 'object',
            'properties' => [
                'agent_role' => [
                    'type' => 'string',
                    'enum' => $rolesEnum,
                    'description' => 'Exact role or ID of the sub-agent to invoke. Allowed values: ' . implode(', ', $rolesEnum) . '.',
                ],
                'task' => [
                    'type' => 'string',
                    'description' => 'Detailed, completely self-contained instructions. CRITICAL: Every sub-agent call is isolated and stateless; it has NO memory of prior calls even for the same role. You MUST include all necessary background context, file paths, and instructions in this prompt.',
                ],
                'context' => [
                    'type' => 'object',
                    'description' => 'Optional structured context, variables, or parameters to pass to the child agent.',
                ],
            ],
            'required' => ['agent_role', 'task'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $role = trim((string)($params['agent_role'] ?? ''));
        $task = trim((string)($params['task'] ?? ''));
        $extraContext = (array)($params['context'] ?? []);

        if ($role === '' || $task === '') {
            return SkillResult::fail('Both agent_role and task parameters are required');
        }

        if ($context->subAgentManager === null) {
            return SkillResult::fail('SubAgentManager is not configured in execution context');
        }

        try {
            /** @var \Harness\Orchestrator\SubAgentManager $mgr */
            $mgr = $context->subAgentManager;
            $childResult = $mgr->spawnChild(
                parentContext: $context,
                childRoleOrId: $role,
                taskInstructions: $task,
                extraContext: $extraContext
            );

            return SkillResult::ok(
                $childResult->outputResult,
                [
                    'child_node_id' => $childResult->id,
                    'prompt_tokens' => $childResult->promptTokens,
                    'completion_tokens' => $childResult->completionTokens,
                    'duration_ms' => $childResult->durationMs,
                ]
            );
        } catch (\Throwable $e) {
            return SkillResult::fail("Sub-agent execution failed: " . $e->getMessage());
        }
    }
}
