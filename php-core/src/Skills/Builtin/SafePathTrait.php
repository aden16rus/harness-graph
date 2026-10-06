<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

trait SafePathTrait
{
    protected function resolveSafePath(string $userPath, string $workspaceBase): string
    {
        $normalizedBase = rtrim(str_replace('\\', '/', realpath($workspaceBase) ?: $workspaceBase), '/');

        // Clean user input
        $cleanUser = str_replace('\\', '/', $userPath);

        // If path is relative, join with workspace base
        if (!str_starts_with($cleanUser, '/') && !preg_match('#^[a-zA-Z]:/#', $cleanUser)) {
            $candidate = $normalizedBase . '/' . ltrim($cleanUser, '/');
        } else {
            $candidate = $cleanUser;
        }

        // Canonicalize path parts (removing . and ..)
        $parts = explode('/', $candidate);
        $resolvedParts = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolvedParts);
            } else {
                $resolvedParts[] = $part;
            }
        }

        $prefix = str_starts_with($candidate, '/') ? '/' : '';
        $resolved = $prefix . implode('/', $resolvedParts);

        // Verify that resolved starts with normalizedBase
        if (!str_starts_with($resolved, $normalizedBase)) {
            throw new \RuntimeException("Access denied: path '{$userPath}' escapes workspace '{$workspaceBase}'");
        }

        return $resolved;
    }
}
