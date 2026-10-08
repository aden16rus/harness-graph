<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class HostExecSkill implements SkillInterface
{
    public const int DEFAULT_MAX_LINES = 100;
    public const int DEFAULT_HEAD_RATIO_PERCENT = 25; // 25 lines head, 75 lines tail

    public function getName(): string
    {
        return 'host_exec';
    }

    public function getDescription(): string
    {
        return 'Executes shell commands directly within the agent environment in workspace directory, with smart Head+Tail line truncation to save context.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Shell command string to execute (e.g. "git status" or "cargo check").',
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
        $maxLines = max(10, (int)($params['max_lines'] ?? self::DEFAULT_MAX_LINES));
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

        $output = "Exit Code: {$exitCode} | Duration: {$duration}ms\n";
        if ($stdout !== '') {
            $output .= "--- STDOUT ---\n" . $this->truncateLines($stdout, $maxLines, $tail) . "\n";
        }
        if ($stderr !== '') {
            $output .= "--- STDERR ---\n" . $this->truncateLines($stderr, $maxLines, $tail) . "\n";
        }

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

    private function truncateLines(string $text, int $maxLines, bool $tail): string
    {
        $lines = explode("\n", rtrim($text, "\r\n"));
        $total = count($lines);
        if ($total <= $maxLines) {
            return $text;
        }

        if ($tail) {
            $kept = array_slice($lines, -$maxLines);
            $omitted = $total - $maxLines;
            return "[... truncated {$omitted} earlier lines; showing last {$maxLines} of {$total} lines. Specify max_lines=9999 for full output ...]\n" . implode("\n", $kept);
        }

        // Smart Head + Tail mode (25% head, 75% tail)
        $headCount = min(25, (int)floor($maxLines * (self::DEFAULT_HEAD_RATIO_PERCENT / 100)));
        $tailCount = $maxLines - $headCount;
        $head = array_slice($lines, 0, $headCount);
        $tailLines = array_slice($lines, -$tailCount);
        $omitted = $total - $maxLines;

        return implode("\n", $head) . "\n"
            . "[... truncated {$omitted} intermediate lines of total {$total}. Specify max_lines=9999 to see complete log ...]\n"
            . implode("\n", $tailLines);
    }
}