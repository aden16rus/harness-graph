<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class DockerExecSkill implements SkillInterface
{
    use CommandOutputTruncationTrait;

    public function getName(): string
    {
        return 'docker_exec';
    }

    public function getDescription(): string
    {
        return 'Executes tests, linters, or build commands inside target project Docker container, with smart Head+Tail line truncation to save context.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cmd' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Command slice to execute, e.g. ["cargo", "check"] or ["npm", "test"].',
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
                'max_lines' => [
                    'type' => 'integer',
                    'description' => 'Maximum output lines to return (optional, default 100: first 25 head + last 75 tail). Pass 9999 to receive complete un-truncated output.',
                    'default' => self::DEFAULT_MAX_LINES,
                ],
                'tail' => [
                    'type' => 'boolean',
                    'description' => 'If true, returns strictly the last max_lines (tail) of output (optional, defaults to false).',
                    'default' => false,
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
        $rawCmd = $params['cmd'] ?? [];
        if (!is_array($rawCmd)) {
            $rawCmd = [$rawCmd];
        }
        $cmd = array_values(array_map('strval', $rawCmd));
        if (empty($cmd)) {
            return SkillResult::fail('Command slice cannot be empty');
        }

        $container = (string)($params['container'] ?? $context->project->defaultContainer ?? '');
        if ($container === '') {
            return SkillResult::fail('No target container specified and project has no default container configured');
        }

        $workDir = (string)($params['work_dir'] ?? '/workspace');
        $timeoutMs = (int)($params['timeout_ms'] ?? 120000);
        $maxLines = max(10, (int)($params['max_lines'] ?? self::DEFAULT_MAX_LINES));
        $tail = (bool)($params['tail'] ?? false);

        try {
            $resp = $context->ipcClient->execDocker($container, $cmd, $workDir, $timeoutMs);
        } catch (\Throwable $e) {
            return SkillResult::fail("Docker exec invocation failed: " . $e->getMessage());
        }

        $exitCode = (int)($resp['exit_code'] ?? 1);
        $stdout = (string)($resp['stdout'] ?? '');
        $stderr = (string)($resp['stderr'] ?? '');
        $duration = (int)($resp['duration_ms'] ?? 0);

        $output = $this->formatAndTruncateOutput(
            exitCode: $exitCode,
            durationMs: $duration,
            stdout: $stdout,
            stderr: $stderr,
            maxLines: $maxLines,
            tail: $tail,
            container: $container
        );

        $data = [
            'exit_code' => $exitCode,
            'duration_ms' => $duration,
            'raw_stdout' => $stdout,
            'raw_stderr' => $stderr,
        ];

        if ($exitCode !== 0) {
            return SkillResult::fail("Command exited with code {$exitCode}", $output, $data);
        }

        return SkillResult::ok($output, $data);
    }
}