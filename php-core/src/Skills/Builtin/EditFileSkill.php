<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class EditFileSkill implements SkillInterface
{
    use SafePathTrait;

    public function getName(): string
    {
        return 'edit_file';
    }

    public function getDescription(): string
    {
        return 'Edits an existing file by replacing an exact snippet of text with replacement content. Provides visual diff in the inspector.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative or absolute path of the file to edit inside workspace.',
                ],
                'old_string' => [
                    'type' => 'string',
                    'description' => 'Exact literal text snippet to find and replace. Must match existing file content.',
                ],
                'new_string' => [
                    'type' => 'string',
                    'description' => 'Replacement text to insert in place of old_string.',
                ],
                'replace_all' => [
                    'type' => 'boolean',
                    'description' => 'Replace all occurrences if true, or only first occurrence (must be unique) if false. Default is false.',
                ],
            ],
            'required' => ['path', 'old_string', 'new_string'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $rawPath = trim((string)($params['path'] ?? ''));
        $oldString = (string)($params['old_string'] ?? '');
        $newString = (string)($params['new_string'] ?? '');
        $replaceAll = (bool)($params['replace_all'] ?? false);

        if ($rawPath === '') {
            return SkillResult::fail('Path parameter is required');
        }
        if ($oldString === '') {
            return SkillResult::fail('old_string parameter cannot be empty');
        }

        try {
            $safePath = $this->resolveSafePath($rawPath, $context->project->workspacePath);
        } catch (\Throwable $e) {
            return SkillResult::fail($e->getMessage());
        }

        if (!file_exists($safePath) || !is_file($safePath)) {
            return SkillResult::fail("File does not exist: {$rawPath}. Use write_file to create new files.");
        }

        $original = @file_get_contents($safePath);
        if ($original === false) {
            return SkillResult::fail("Failed to read file: {$rawPath}");
        }

        $count = substr_count($original, $oldString);
        if ($count === 0) {
            return SkillResult::fail("Target old_string not found in {$rawPath}. Please check exact text using read_file.");
        }

        if ($count > 1 && !$replaceAll) {
            return SkillResult::fail("Target old_string matched {$count} times in {$rawPath}. Provide more surrounding context to make it unique, or set replace_all=true.");
        }

        if ($replaceAll) {
            $modified = str_replace($oldString, $newString, $original);
        } else {
            $pos = strpos($original, $oldString);
            $modified = substr_replace($original, $newString, $pos, strlen($oldString));
        }

        $written = @file_put_contents($safePath, $modified);
        if ($written === false) {
            return SkillResult::fail("Failed to write updated content to {$rawPath}");
        }

        $oldLines = substr_count($oldString, "\n");
        $newLines = substr_count($newString, "\n");
        $summary = "Successfully edited {$rawPath} (replaced {$count} occurrence(s), -{$oldLines} lines, +{$newLines} lines)";

        return SkillResult::ok(
            $summary,
            [
                'path' => $rawPath,
                'old_content' => $original,
                'new_content' => $modified,
                'old_string' => $oldString,
                'new_string' => $newString,
                'bytes' => $written,
                'is_edit' => true,
            ]
        );
    }
}