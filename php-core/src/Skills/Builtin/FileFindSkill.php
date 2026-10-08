<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class FileFindSkill implements SkillInterface
{
    use SafePathTrait;

    private const array IGNORED_DIRS = ['.git', 'node_modules', 'target', 'vendor', '.cache', 'dist', 'build'];

    public function getName(): string
    {
        return 'file_find';
    }

    public function getDescription(): string
    {
        return 'Finds files by filename or glob pattern across workspace without manual folder traversal.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Filename or glob pattern to search for, e.g. "*.toml", "main.rs", "*config*".',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Subdirectory to search within (relative to workspace). Defaults to "." (entire workspace).',
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Maximum matching files to return (default: 50).',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $pattern = trim((string)($params['pattern'] ?? ''));
        $subPath = trim((string)($params['path'] ?? '.'));
        $maxResults = min(200, max(1, (int)($params['max_results'] ?? 50)));

        if ($pattern === '') {
            return SkillResult::fail('Search pattern cannot be empty');
        }

        try {
            $baseDir = $this->resolveSafePath($subPath, $context->project->workspacePath);
        } catch (\Throwable $e) {
            return SkillResult::fail($e->getMessage());
        }

        if (!is_dir($baseDir)) {
            return SkillResult::fail("Directory does not exist: {$subPath}");
        }

        $workspaceRoot = realpath($context->project->workspacePath) ?: $context->project->workspacePath;
        $matches = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                function (\SplFileInfo $file) {
                    if ($file->isDir()) {
                        return !in_array($file->getFilename(), self::IGNORED_DIRS, true);
                    }
                    return true;
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if (count($matches) >= $maxResults) {
                break;
            }
            if ($fileInfo->isDir() || !$fileInfo->isFile()) {
                continue;
            }

            $filename = $fileInfo->getFilename();
            $matched = fnmatch($pattern, $filename, FNM_CASEFOLD);

            if ($matched) {
                $fullPath = $fileInfo->getPathname();
                $relPath = str_replace('\\', '/', substr($fullPath, strlen($workspaceRoot)));
                $relPath = ltrim($relPath, '/');

                $matches[] = [
                    'path' => $relPath,
                    'size' => $fileInfo->getSize(),
                    'modified' => gmdate('Y-m-d H:i:s', $fileInfo->getMTime()),
                ];
            }
        }

        if (empty($matches)) {
            return SkillResult::ok("No files found matching pattern: \"{$pattern}\" in {$subPath}", [
                'pattern' => $pattern,
                'path' => $subPath,
                'count' => 0,
            ]);
        }

        $lines = ["Found " . count($matches) . " file(s) matching \"{$pattern}\" in {$subPath}:"];
        foreach ($matches as $m) {
            $sizeStr = $m['size'] > 1024 * 1024
                ? round($m['size'] / (1024 * 1024), 1) . ' MB'
                : ($m['size'] > 1024 ? round($m['size'] / 1024, 1) . ' KB' : $m['size'] . ' B');
            $lines[] = sprintf("  [FILE] %-35s (%s, %s)", $m['path'], $sizeStr, $m['modified']);
        }

        if (count($matches) >= $maxResults) {
            $lines[] = "\n[... reached limit of {$maxResults} files. Refine pattern if needed ...]";
        }

        return SkillResult::ok(implode("\n", $lines), [
            'pattern' => $pattern,
            'path' => $subPath,
            'count' => count($matches),
            'files' => array_column($matches, 'path'),
        ]);
    }
}