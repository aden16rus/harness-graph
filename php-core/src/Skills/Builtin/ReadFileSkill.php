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
        return 'Reads content of a file located within the project workspace.';
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
                'offset' => [
                    'type' => 'integer',
                    'description' => '1-based line number to start reading from (optional).',
                    'default' => 1,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of lines to read (optional).',
                    'default' => 200,
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

        $offset = max(1, (int)($params['offset'] ?? 1));
        $limit = max(1, (int)($params['limit'] ?? 200));

        $slice = array_slice($lines, $offset - 1, $limit);
        $output = '';
        foreach ($slice as $i => $line) {
            $lineNum = $offset + $i;
            $output .= sprintf("%4d | %s", $lineNum, $line);
        }

        return SkillResult::ok($output, ['total_lines' => count($lines)]);
    }
}
