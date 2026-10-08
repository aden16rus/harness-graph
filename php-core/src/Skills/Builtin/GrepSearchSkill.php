<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class GrepSearchSkill implements SkillInterface
{
    use SafePathTrait;

    private const array IGNORED_DIRS = ['.git', 'node_modules', 'target', 'vendor', '.cache', 'dist', 'build'];

    public function getName(): string
    {
        return 'grep_search';
    }

    public function getDescription(): string
    {
        return 'Searches file contents within workspace using text or regex. Returns matching lines with line numbers and file paths.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Text string or regex pattern to search for in files.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Directory or file path to search in (relative to workspace). Defaults to "." (entire workspace).',
                ],
                'include' => [
                    'type' => 'string',
                    'description' => 'File extension or glob pattern to limit search, e.g. "*.rs", "*.ts", "*.php".',
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Maximum matching lines to return (default: 50, max: 200).',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $pattern = (string)($params['pattern'] ?? '');
        $subPath = (string)($params['path'] ?? '.');
        $include = trim((string)($params['include'] ?? ''));
        $maxResults = min(200, max(1, (int)($params['max_results'] ?? 50)));

        if ($pattern === '') {
            return SkillResult::fail('Search pattern cannot be empty');
        }

        try {
            $baseDir = $this->resolveSafePath($subPath, $context->project->workspacePath);
        } catch (\Throwable $e) {
            return SkillResult::fail($e->getMessage());
        }

        if (!is_dir($baseDir) && !is_file($baseDir)) {
            return SkillResult::fail("Path does not exist: {$subPath}");
        }

        $workspaceRoot = realpath($context->project->workspacePath) ?: $context->project->workspacePath;
        $matches = [];
        $totalMatches = 0;

        if (is_file($baseDir)) {
            $this->searchFile($baseDir, $workspaceRoot, $pattern, $matches, $totalMatches, $maxResults);
        } else {
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
                if ($totalMatches >= $maxResults) {
                    break;
                }
                if ($fileInfo->isDir() || !$fileInfo->isFile()) {
                    continue;
                }

                $filename = $fileInfo->getFilename();
                if ($include !== '' && !fnmatch($include, $filename)) {
                    continue;
                }

                // Skip binary or huge files (> 2MB)
                if ($fileInfo->getSize() > 2 * 1024 * 1024) {
                    continue;
                }

                $this->searchFile($fileInfo->getPathname(), $workspaceRoot, $pattern, $matches, $totalMatches, $maxResults);
            }
        }

        if (empty($matches)) {
            return SkillResult::ok("No matches found for pattern: \"{$pattern}\" in {$subPath}", [
                'pattern' => $pattern,
                'path' => $subPath,
                'matches_count' => 0,
            ]);
        }

        $lines = ["Found {$totalMatches} match(es) for \"{$pattern}\" in {$subPath}:"];
        $grouped = [];
        foreach ($matches as $m) {
            $grouped[$m['file']][] = $m;
        }

        foreach ($grouped as $file => $fileMatches) {
            $lines[] = "\n--- {$file} ---";
            foreach ($fileMatches as $fm) {
                $lines[] = sprintf("  %4d | %s", $fm['line'], $fm['text']);
            }
        }

        if ($totalMatches >= $maxResults) {
            $lines[] = "\n[... reached limit of {$maxResults} results. Refine pattern or path if needed ...]";
        }

        return SkillResult::ok(implode("\n", $lines), [
            'pattern' => $pattern,
            'path' => $subPath,
            'matches_count' => $totalMatches,
            'files_count' => count($grouped),
        ]);
    }

    private function searchFile(
        string $filePath,
        string $workspaceRoot,
        string $pattern,
        array &$matches,
        int &$totalMatches,
        int $maxResults
    ): void {
        $content = @file_get_contents($filePath);
        if ($content === false || str_contains(substr($content, 0, 512), "\0")) {
            return; // binary or unreadable
        }

        $relPath = str_replace('\\', '/', substr($filePath, strlen($workspaceRoot)));
        $relPath = ltrim($relPath, '/');

        $lines = explode("\n", $content);
        $isRegex = str_starts_with($pattern, '/') && str_ends_with($pattern, '/');

        foreach ($lines as $idx => $line) {
            if ($totalMatches >= $maxResults) {
                break;
            }

            $matched = false;
            if ($isRegex) {
                $matched = (bool)@preg_match($pattern, $line);
            } else {
                $matched = stripos($line, $pattern) !== false;
            }

            if ($matched) {
                $matches[] = [
                    'file' => $relPath,
                    'line' => $idx + 1,
                    'text' => trim($line),
                ];
                $totalMatches++;
            }
        }
    }
}