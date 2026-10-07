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
        return 'Executes shell commands directly within the agent environment in workspace directory, with output line limit and tail options.';
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
        $maxLines = max(10, (int)($params['max_lines'] ?? 150));
        $tail = (bool)($params['tail'] ?? false);

        try {
            $resp = $context->ipcClient->execLocal([$command], $context->project->workspacePath, $timeoutMs);
        } catch (\Throwable $e) {
            return SkillResult::fail("Local exec failed: " . $e->getMessage());
        }

        $exitCode = (int)($resp['exit_code'] ?? 1);
        $stdout = (string)($resp['stdout'] ?? '');
        $stderr = (string)($resp['stderr'] ?? '');
        $duration = (int)($resp['duration_ms'] ?? 0);

        $output = "Exit Code: {$exitCode} | Duration: {$duration}ms
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

        return SkillResult::ok($output, ['exit_code' => $exitCode, 'duration_ms' => $duration]);
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
