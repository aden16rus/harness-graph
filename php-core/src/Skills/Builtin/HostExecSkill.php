<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class HostExecSkill implements SkillInterface
{
    public function getName(): string
    {
        return 'host_exec';
    }

    public function getDescription(): string
    {
        return 'Executes shell commands directly within the agent environment in workspace directory.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Shell command string to execute (e.g. "git status" or "composer validate").',
                ],
                'timeout_ms' => [
                    'type' => 'integer',
                    'description' => 'Execution timeout in milliseconds (optional, defaults to 60000).',
                    'default' => 60000,
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $command = trim((string)($params['command'] ?? ''));
        if ($command === '') {
            return SkillResult::fail('Command string is required');
        }

        $timeoutMs = (int)($params['timeout_ms'] ?? 60000);

        try {
            $resp = $context->ipcClient->execLocal([$command], $context->project->workspacePath, $timeoutMs);
        } catch (\Throwable $e) {
            return SkillResult::fail("Local exec failed: " . $e->getMessage());
        }

        $exitCode = (int)($resp['exit_code'] ?? 1);
        $stdout = (string)($resp['stdout'] ?? '');
        $stderr = (string)($resp['stderr'] ?? '');
        $duration = (int)($resp['duration_ms'] ?? 0);

        $output = "Exit Code: {$exitCode} | Duration: {$duration}ms\n";
        if ($stdout !== '') {
            $output .= "--- STDOUT ---\n" . $stdout . "\n";
        }
        if ($stderr !== '') {
            $output .= "--- STDERR ---\n" . $stderr . "\n";
        }

        if ($exitCode !== 0) {
            return SkillResult::fail("Command exited with code {$exitCode}", $output);
        }

        return SkillResult::ok($output, ['exit_code' => $exitCode, 'duration_ms' => $duration]);
    }
}
