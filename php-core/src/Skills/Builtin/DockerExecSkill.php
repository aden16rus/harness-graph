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
        return 'Executes tests, linters, or build commands inside target project Docker container, with output line limit and tail options.';
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
                    'description' => 'Maximum number of output lines to return (optional, defaults to 150). Prevents context overflow on verbose commands.',
                    'default' => 150,
                ],
                'tail' => [
                    'type' => 'boolean',
                    'description' => 'If true, returns the last max_lines (tail) of output instead of head/middle (optional, defaults to false).',
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
        // Normalize array keys to 0, 1, ... so Go unmarshals as []string
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
        $maxLines = max(10, (int)($params['max_lines'] ?? 150));
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

        $output = "Container: {$container} | Exit Code: {$exitCode} | Duration: {$duration}ms
";
        if ($stdout !== '') {
            $output .= "--- STDOUT ---
" . $this->truncateLines($stdout, $maxLines, $tail) . "
";
        }
        if ($stderr !== '') {
            $output .= "--- STDERR ---
" . $this->truncateLines($stderr, $maxLines, $tail) . "
";
        }

        if ($exitCode !== 0) {
            return SkillResult::fail("Command exited with code {$exitCode}", $output);
        }

        return SkillResult::ok($output, [
            'exit_code' => $exitCode,
            'duration_ms' => $duration,
        ]);
    }

    private function truncateLines(string $text, int $maxLines, bool $tail): string
    {
        $lines = explode("
", rtrim($text, "
"));
        $total = count($lines);
        if ($total <= $maxLines) {
            return $text;
        }

        if ($tail) {
            $kept = array_slice($lines, -$maxLines);
            $omitted = $total - $maxLines;
            return "[... truncated {$omitted} earlier lines; showing last {$maxLines} of {$total} lines ...]
" . implode("
", $kept);
        }

        if ($maxLines >= 20) {
            $headCount = (int)floor($maxLines * 0.7);
            $tailCount = $maxLines - $headCount;
            $head = array_slice($lines, 0, $headCount);
            $tailLines = array_slice($lines, -$tailCount);
            $omitted = $total - $maxLines;
            return implode("
", $head) . "
[... truncated {$omitted} intermediate lines of total {$total} ...]
" . implode("
", $tailLines);
        }

        $head = array_slice($lines, 0, $maxLines);
        $omitted = $total - $maxLines;
        return implode("
", $head) . "
[... truncated {$omitted} remaining lines of total {$total} ...]";
    }
}
