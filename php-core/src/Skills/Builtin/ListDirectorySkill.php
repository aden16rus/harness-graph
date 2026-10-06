<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class ListDirectorySkill implements SkillInterface
{
    use SafePathTrait;

    public function getName(): string
    {
        return 'list_dir';
    }

    public function getDescription(): string
    {
        return 'Lists entries in a directory within the workspace.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative path to directory inside workspace (defaults to root).',
                    'default' => '.',
                ],
            ],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $rawPath = (string)($params['path'] ?? '.');

        try {
            $safePath = $this->resolveSafePath($rawPath, $context->project->workspacePath);
        } catch (\Throwable $e) {
            return SkillResult::fail($e->getMessage());
        }

        if (!file_exists($safePath)) {
            return SkillResult::fail("Directory not found: {$rawPath}");
        }

        if (!is_dir($safePath)) {
            return SkillResult::fail("Path is not a directory: {$rawPath}");
        }

        $items = @scandir($safePath);
        if ($items === false) {
            return SkillResult::fail("Unable to read directory: {$rawPath}");
        }

        $output = "Directory listing for {$rawPath}:\n";
        $entries = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullItem = $safePath . DIRECTORY_SEPARATOR . $item;
            $isDir = is_dir($fullItem);
            $type = $isDir ? '[DIR] ' : '[FILE]';
            $size = $isDir ? '-' : sprintf('%d bytes', filesize($fullItem));
            $output .= sprintf("  %-6s  %-30s  %s\n", $type, $item, $size);
            $entries[] = ['name' => $item, 'type' => $isDir ? 'dir' : 'file'];
        }

        return SkillResult::ok($output, ['entries' => $entries]);
    }
}
