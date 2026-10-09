<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

trait CommandOutputTruncationTrait
{
    private const int DEFAULT_MAX_LINES = 100;
    private const int MAX_LINE_LENGTH = 1000;
    private const int MAX_OUTPUT_BYTES = 24576; // 24 KB

    private function formatAndTruncateOutput(
        int $exitCode,
        int $durationMs,
        string $stdout,
        string $stderr,
        int $maxLines = self::DEFAULT_MAX_LINES,
        bool $tail = false,
        ?string $container = null,
        string $commandContext = ''
    ): string {
        $prefix = $container !== null
            ? "Container: {$container} | Exit Code: {$exitCode} | Duration: {$durationMs}ms\n"
            : "Exit Code: {$exitCode} | Duration: {$durationMs}ms\n";

        // If explicitly requested un-truncated output (e.g. max_lines >= 9000)
        if ($maxLines >= 9000) {
            $res = $prefix;
            if ($stdout !== '') {
                $res .= "--- STDOUT ---\n{$stdout}\n";
            }
            if ($stderr !== '') {
                $res .= "--- STDERR ---\n{$stderr}\n";
            }
            return $res;
        }

        // 1. Sanitize CRLF and truncate overly long individual lines (e.g. minified JS, base64)
        $cleanLines = static function (string $text): array {
            if ($text === '') {
                return [];
            }
            $normalized = str_replace(["\r\n", "\r"], "\n", rtrim($text));
            $rawLines = explode("\n", $normalized);
            $clean = [];
            foreach ($rawLines as $line) {
                if (strlen($line) > self::MAX_LINE_LENGTH) {
                    $clean[] = substr($line, 0, self::MAX_LINE_LENGTH - 40) . '... [line truncated]';
                } else {
                    $clean[] = $line;
                }
            }
            return $clean;
        };

        $outLines = $cleanLines($stdout);
        $errLines = $cleanLines($stderr);

        // Build combined lines with stream markers
        $combined = [];
        if (!empty($outLines)) {
            $combined[] = '--- STDOUT ---';
            foreach ($outLines as $l) {
                $combined[] = $l;
            }
        }
        if (!empty($errLines)) {
            $combined[] = '--- STDERR ---';
            foreach ($errLines as $l) {
                $combined[] = $l;
            }
        }

        $total = count($combined);

        // Auto-optimize successful verbose build/install commands if caller left default 100 lines
        $isAutoBuildTail = false;
        if ($exitCode === 0 && $maxLines === self::DEFAULT_MAX_LINES && !$tail && $total > 25 && $commandContext !== '') {
            $cmdLower = strtolower($commandContext);
            if (
                str_contains($cmdLower, 'docker build')
                || str_contains($cmdLower, 'docker-compose build')
                || str_contains($cmdLower, 'docker pull')
                || str_contains($cmdLower, 'npm install')
                || str_contains($cmdLower, 'npm i')
                || str_contains($cmdLower, 'yarn add')
                || str_contains($cmdLower, 'pnpm i')
                || str_contains($cmdLower, 'apt-get install')
                || str_contains($cmdLower, 'apt-get update')
                || str_contains($cmdLower, 'composer install')
                || str_contains($cmdLower, 'cargo build')
            ) {
                $tail = true;
                $maxLines = 12;
                $isAutoBuildTail = true;
            }
        }

        $resultText = '';

        if ($total <= $maxLines) {
            $resultText = $prefix . implode("\n", $combined) . "\n";
        } else {
            if ($tail) {
                $kept = array_slice($combined, -$maxLines);
                $omitted = $total - $maxLines;
                $notice = $isAutoBuildTail
                    ? "[... Build/install succeeded (exit code 0). {$omitted} intermediate build lines omitted. Showing final {$maxLines} lines ...]\n"
                    : "[... truncated {$omitted} earlier lines; showing last {$maxLines} of {$total} lines. Specify max_lines=9999 for full output ...]\n";

                $resultText = $prefix . $notice . implode("\n", $kept) . "\n";
            } else {
                $headCount = min(25, (int)floor($maxLines * 0.25));
                $tailCount = $maxLines - $headCount;
                $head = array_slice($combined, 0, $headCount);
                $tailLines = array_slice($combined, -$tailCount);
                $omitted = $total - $maxLines;

                $resultText = $prefix
                    . implode("\n", $head) . "\n"
                    . "[... truncated {$omitted} intermediate lines of total {$total}. Specify max_lines=9999 to see complete log ...]\n"
                    . implode("\n", $tailLines) . "\n";
            }
        }

        // 2. Absolute byte safety fallback: if result is still larger than MAX_OUTPUT_BYTES
        if (strlen($resultText) > self::MAX_OUTPUT_BYTES) {
            $headBytes = substr($resultText, 0, 6144);
            $tailBytes = substr($resultText, -14336);
            $omittedBytes = strlen($resultText) - (6144 + 14336);
            $resultText = $headBytes . "\n[... truncated {$omittedBytes} bytes of output. Specify max_lines=9999 for full output ...]\n" . $tailBytes;
        }

        return $resultText;
    }
}