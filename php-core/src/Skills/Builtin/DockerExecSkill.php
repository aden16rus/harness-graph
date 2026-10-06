<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class DockerExecSkill implements SkillInterface
{
    public function getName(): string
    {
        return 'docker_exec';
    }

    public function getDescription(): string
    {
        return 'Executes tests, linters, or build commands inside target project Docker container.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cmd' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Command slice to execute, e.g. ["vendor/bin/phpunit", "tests/"] or ["npm", "test"].',
                ],
                'container' => [
                    'type' => 'string',
                    'description' => 'Target container ID or name (optional, defaults to project container).',
                ],
                'work_dir' => [
                    'type' => 'string',
                    'description' => 'Working directory inside container (optional, defaults to /workspace).',
                    'default' => '/workspace',
                ],
                'timeout_ms' => [
                    'type' => 'integer',
                    'description' => 'Timeout in milliseconds (optional, defaults to 120000).',
                    'default' => 120000,
                ],
            ],
            'required' => ['cmd'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $cmd = (array)($params['cmd'] ?? []);
        if (empty($cmd)) {
            return SkillResult::fail('Command slice cannot be empty');
        }

        $container = (string)($params['container'] ?? $context->project->defaultContainer ?? '');
        if ($container === '') {
            return SkillResult::fail('No target container specified and project has no default container configured');
        }

        $workDir = (string)($params['work_dir'] ?? '/workspace');
        $timeoutMs = (int)($params['timeout_ms'] ?? 120000);

        try {
            $resp = $context->ipcClient->execDocker($container, $cmd, $workDir, $timeoutMs);
        } catch (\Throwable $e) {
            return SkillResult::fail("Docker exec invocation failed: " . $e->getMessage());
        }

        $exitCode = (int)($resp['exit_code'] ?? 1);
        $stdout = (string)($resp['stdout'] ?? '');
        $stderr = (string)($resp['stderr'] ?? '');
        $duration = (int)($resp['duration_ms'] ?? 0);

        $output = "Container: {$container} | Exit Code: {$exitCode} | Duration: {$duration}ms\n";
        if ($stdout !== '') {
            $output .= "--- STDOUT ---\n" . $stdout . "\n";
        }
        if ($stderr !== '') {
            $output .= "--- STDERR ---\n" . $stderr . "\n";
        }

        if ($exitCode !== 0) {
            return SkillResult::fail("Command exited with code {$exitCode}", $output);
        }

        return SkillResult::ok($output, [
            'exit_code' => $exitCode,
            'duration_ms' => $duration,
        ]);
    }
}
