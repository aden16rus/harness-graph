<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class WriteFileSkill implements SkillInterface
{
    use SafePathTrait;

    public function getName(): string
    {
        return 'write_file';
    }

    public function getDescription(): string
    {
        return 'Creates or completely overwrites a file with content within the workspace.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative or absolute path of the file to write inside workspace.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Full text content to write into the file.',
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $rawPath = (string)($params['path'] ?? '');
        $content = (string)($params['content'] ?? '');

        if ($rawPath === '') {
            return SkillResult::fail('Path parameter is required');
        }

        try {
            $safePath = $this->resolveSafePath($rawPath, $context->project->workspacePath);
        } catch (\Throwable $e) {
            return SkillResult::fail($e->getMessage());
        }

        $dir = dirname($safePath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return SkillResult::fail("Failed to create directory structure: {$dir}");
        }

        $original = file_exists($safePath) ? @file_get_contents($safePath) : false;

        $written = @file_put_contents($safePath, $content);
        if ($written === false) {
            return SkillResult::fail("Failed to write to file: {$rawPath}");
        }

        $payload = [
            'path' => $rawPath,
            'bytes' => $written,
        ];

        if ($original !== false) {
            $payload['old_content'] = $original;
            $payload['new_content'] = $content;
            $payload['is_edit'] = true;
        }

        return SkillResult::ok(
            "Successfully wrote {$written} bytes to {$rawPath}",
            $payload
        );
    }
}