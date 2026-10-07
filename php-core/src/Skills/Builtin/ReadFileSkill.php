<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class ReadFileSkill implements SkillInterface
{
    use SafePathTrait;

    public function getName(): string
    {
        return 'read_file';
    }

    public function getDescription(): string
    {
        return 'Reads content of a file located within the project workspace, with optional line range support (start_line to end_line or offset/limit).';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative or absolute path to the file inside workspace.',
                ],
                'start_line' => [
                    'type' => 'integer',
                    'description' => '1-based line number to start reading from (optional, defaults to 1). Alias: offset.',
                    'default' => 1,
                ],
                'end_line' => [
                    'type' => 'integer',
                    'description' => '1-based line number to stop reading at inclusive (optional). If specified, reads lines from start_line up to end_line.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => '1-based line number to start reading from (alias for start_line).',
                    'default' => 1,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of lines to read (optional, defaults to 200). Ignored if end_line is specified.',
                    'default' => 200,
                ],
                'max_lines' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of lines to read (alias for limit).',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $rawPath = (string)($params['path'] ?? '');
        if ($rawPath === '') {
            return SkillResult::fail('Path parameter is required');
        }

        try {
            $safePath = $this->resolveSafePath($rawPath, $context->project->workspacePath);
        } catch (\Throwable $e) {
            return SkillResult::fail($e->getMessage());
        }

        if (!file_exists($safePath)) {
            return SkillResult::fail("File not found: {$rawPath}");
        }

        if (!is_file($safePath)) {
            return SkillResult::fail("Path is a directory, not a file: {$rawPath}");
        }

        $lines = @file($safePath);
        if ($lines === false) {
            return SkillResult::fail("Unable to read file: {$rawPath}");
        }

        $totalLines = count($lines);
        $startLine = max(1, (int)($params['start_line'] ?? ($params['from_line'] ?? ($params['offset'] ?? 1))));

        if ($startLine > $totalLines) {
            return SkillResult::ok(
                sprintf("File '%s' has %d lines. Requested start_line (%d) is beyond end of file.", $rawPath, $totalLines, $startLine),
                [
                    'total_lines' => $totalLines,
                    'start_line' => $startLine,
                    'end_line' => $totalLines,
                    'lines_returned' => 0,
                ]
            );
        }

        if (isset($params['end_line']) || isset($params['to_line'])) {
            $reqEnd = (int)($params['end_line'] ?? $params['to_line']);
            $endLine = min($totalLines, max($startLine, $reqEnd));
            $limit = $endLine - $startLine + 1;
        } else {
            $limit = max(1, (int)($params['limit'] ?? ($params['max_lines'] ?? 200)));
            $endLine = min($totalLines, $startLine + $limit - 1);
        }

        $slice = array_slice($lines, $startLine - 1, $limit);
        $actualReturned = count($slice);
        $actualEnd = $startLine + $actualReturned - 1;

        $output = sprintf("--- File: %s (Lines %d-%d of %d) ---
", $rawPath, $startLine, $actualEnd, $totalLines);
        foreach ($slice as $i => $line) {
            $lineNum = $startLine + $i;
            $output .= sprintf("%4d | %s", $lineNum, $line);
        }

        if ($actualEnd < $totalLines) {
            $remaining = $totalLines - $actualEnd;
            $output .= sprintf("
[... %d more lines remaining in file. Use start_line=%d to continue reading ...]
", $remaining, $actualEnd + 1);
        }

        return SkillResult::ok($output, [
            'total_lines' => $totalLines,
            'start_line' => $startLine,
            'end_line' => $actualEnd,
            'lines_returned' => $actualReturned,
        ]);
    }
}
