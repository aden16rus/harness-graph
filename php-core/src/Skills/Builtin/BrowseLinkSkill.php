<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class BrowseLinkSkill implements SkillInterface
{
    public function getName(): string
    {
        return 'browse_link';
    }

    public function getDescription(): string
    {
        return 'Opens a web link or URL in an isolated headless Chromium browser container (MCP), renders client-side JavaScript, and extracts page title, status code, clean text content, and links.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The complete HTTP or HTTPS URL to open in browser (e.g. "https://example.com" or "https://github.com").',
                ],
                'wait_selector' => [
                    'type' => 'string',
                    'description' => 'Optional CSS selector to wait for before extracting page content (e.g. "#content" or "article").',
                ],
                'extract_html' => [
                    'type' => 'boolean',
                    'description' => 'If true, returns full HTML source; if false (default), returns rendered readable text.',
                    'default' => false,
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $url = trim((string)($params['url'] ?? ''));
        if ($url === '') {
            return SkillResult::fail('URL parameter is required');
        }

        $waitSelector = !empty($params['wait_selector']) ? (string)$params['wait_selector'] : null;
        $extractHtml = (bool)($params['extract_html'] ?? false);

        $mcpUrl = getenv('BROWSER_MCP_URL') ?: 'http://browser-mcp:3000';
        $mcpEndpoint = rtrim($mcpUrl, '/') . '/mcp';
        $browseEndpoint = rtrim($mcpUrl, '/') . '/browse';

        // 1. Try MCP JSON-RPC 2.0 protocol
        $rpcPayload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'mcp_' . bin2hex(random_bytes(4)),
            'method' => 'tools/call',
            'params' => [
                'name' => 'browse_link',
                'arguments' => array_filter([
                    'url' => $url,
                    'wait_selector' => $waitSelector,
                    'extract_html' => $extractHtml,
                ], fn($v) => $v !== null),
            ],
        ]);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $rpcPayload,
                'timeout' => 40,
                'ignore_errors' => true,
            ],
        ];

        $resp = @file_get_contents($mcpEndpoint, false, stream_context_create($opts));
        if ($resp !== false) {
            $data = json_decode($resp, true);
            if (is_array($data) && isset($data['result']['content'])) {
                $textContent = '';
                foreach ($data['result']['content'] as $item) {
                    if (($item['type'] ?? '') === 'text') {
                        $textContent .= ($textContent !== '' ? "\n\n" : '') . ($item['text'] ?? '');
                    }
                }
                if ($textContent !== '') {
                    return SkillResult::ok("[Browser MCP]\n" . $textContent, [
                        'url' => $url,
                        'protocol' => 'mcp-jsonrpc-2.0',
                    ]);
                }
            } elseif (is_array($data) && !empty($data['error'])) {
                $errMsg = is_array($data['error']) ? ($data['error']['message'] ?? json_encode($data['error'])) : (string)$data['error'];
                return SkillResult::fail("Browser MCP error: {$errMsg}");
            }
        }

        // 2. Fallback to direct /browse endpoint if /mcp did not succeed
        $directPayload = json_encode([
            'url' => $url,
            'wait_selector' => $waitSelector,
            'extract_html' => $extractHtml,
            'timeout_ms' => 30000,
        ]);

        $directOpts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $directPayload,
                'timeout' => 35,
                'ignore_errors' => true,
            ],
        ];

        $directResp = @file_get_contents($browseEndpoint, false, stream_context_create($directOpts));
        if ($directResp === false) {
            return SkillResult::fail("Failed to connect to Browser MCP container at {$mcpUrl}");
        }

        $directData = json_decode($directResp, true);
        if (!is_array($directData)) {
            return SkillResult::fail("Invalid response from Browser MCP: {$directResp}");
        }

        if (empty($directData['success'])) {
            $err = (string)($directData['error'] ?? 'Unknown browser navigation error');
            return SkillResult::fail("Browser navigation error: {$err}");
        }

        $title = (string)($directData['title'] ?? 'No Title');
        $finalUrl = (string)($directData['url'] ?? $url);
        $status = (int)($directData['status'] ?? 200);
        $content = (string)($directData['content'] ?? '');
        $links = $directData['links'] ?? [];

        $linksFormatted = '';
        if (is_array($links) && count($links) > 0) {
            $linksFormatted = "\n\n--- Page Links ---\n";
            foreach (array_slice($links, 0, 15) as $l) {
                $text = trim((string)($l['text'] ?? 'Link'));
                $href = (string)($l['href'] ?? '');
                $linksFormatted .= "- [{$text}]({$href})\n";
            }
        }

        $output = "[Browser MCP] Title: {$title}\nURL: {$finalUrl} (HTTP {$status})\n\n--- Page Content ---\n{$content}{$linksFormatted}";

        return SkillResult::ok($output, [
            'title' => $title,
            'url' => $finalUrl,
            'status' => $status,
            'content_length' => strlen($content),
            'links_count' => is_array($links) ? count($links) : 0,
        ]);
    }
}
