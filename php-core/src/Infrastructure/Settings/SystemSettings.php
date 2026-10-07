<?php
declare(strict_types=1);

namespace Harness\Infrastructure\Settings;

use PDO;

final class SystemSettings
{
    public function __construct(
        public int $subagentMaxSteps = 15,
        public int $rootMaxSteps = 25,
        public int $subagentMaxTokens = 50000,
        public bool $loopProtectionEnabled = true,
        public int $loopDetectionThreshold = 3,
        public int $llmMaxRetries = 3,
        public int $llmRetryDelaySec = 3,
        public string $globalSystemPrompt = ''
    ) {}

    public static function load(?string $filePath = null, ?PDO $pdo = null): self
    {
        $filePath = $filePath ?? (getenv('DATA_DIR') ? getenv('DATA_DIR') . '/settings.json' : '/data/settings.json');
        $data = [];

        // 1. Try loading from settings.json
        if (file_exists($filePath)) {
            $raw = @file_get_contents($filePath);
            if ($raw !== false && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        // 2. If settings.json had no data or was missing, fallback to SQLite system_settings table
        if (empty($data) && $pdo !== null) {
            try {
                $stmt = $pdo->query("SELECT key, value FROM system_settings");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $k = (string)$row['key'];
                        $v = (string)$row['value'];
                        if ($k === 'loop_protection_enabled') {
                            $data[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                        } elseif (in_array($k, ['subagent_max_steps', 'root_max_steps', 'subagent_max_tokens', 'loop_detection_threshold', 'llm_max_retries', 'llm_retry_delay_sec'], true)) {
                            $data[$k] = (int)$v;
                        } else {
                            $data[$k] = $v;
                        }
                    }
                }
            } catch (\Throwable) {
                // Table might not exist yet
            }
        }

        $subagentSteps = isset($data['subagent_max_steps'])
            ? (int)$data['subagent_max_steps']
            : (int)(getenv('SUBAGENT_MAX_STEPS') ?: 15);

        $rootSteps = isset($data['root_max_steps'])
            ? (int)$data['root_max_steps']
            : (int)(getenv('ROOT_MAX_STEPS') ?: 25);

        $subagentTokens = isset($data['subagent_max_tokens'])
            ? (int)$data['subagent_max_tokens']
            : (int)(getenv('SUBAGENT_MAX_TOKENS') ?: 50000);

        $loopProt = isset($data['loop_protection_enabled'])
            ? (bool)$data['loop_protection_enabled']
            : (getenv('LOOP_PROTECTION_ENABLED') !== false && getenv('LOOP_PROTECTION_ENABLED') !== ''
                ? filter_var(getenv('LOOP_PROTECTION_ENABLED'), FILTER_VALIDATE_BOOLEAN)
                : true);

        $loopThresh = isset($data['loop_detection_threshold'])
            ? (int)$data['loop_detection_threshold']
            : (int)(getenv('LOOP_DETECTION_THRESHOLD') ?: 3);

        $llmRetries = isset($data['llm_max_retries'])
            ? (int)$data['llm_max_retries']
            : (int)(getenv('LLM_MAX_RETRIES') ?: 3);

        $llmDelay = isset($data['llm_retry_delay_sec'])
            ? (int)$data['llm_retry_delay_sec']
            : (int)(getenv('LLM_RETRY_DELAY_SEC') ?: 3);

        $globalPrompt = isset($data['global_system_prompt'])
            ? (string)$data['global_system_prompt']
            : (string)(getenv('GLOBAL_SYSTEM_PROMPT') ?: '');

        return new self(
            subagentMaxSteps: max(1, $subagentSteps),
            rootMaxSteps: max(1, $rootSteps),
            subagentMaxTokens: max(0, $subagentTokens),
            loopProtectionEnabled: $loopProt,
            loopDetectionThreshold: max(2, $loopThresh),
            llmMaxRetries: max(0, $llmRetries),
            llmRetryDelaySec: max(1, $llmDelay),
            globalSystemPrompt: $globalPrompt
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            subagentMaxSteps: max(1, (int)($data['subagent_max_steps'] ?? 15)),
            rootMaxSteps: max(1, (int)($data['root_max_steps'] ?? 25)),
            subagentMaxTokens: max(0, (int)($data['subagent_max_tokens'] ?? 50000)),
            loopProtectionEnabled: isset($data['loop_protection_enabled']) ? (bool)$data['loop_protection_enabled'] : true,
            loopDetectionThreshold: max(2, (int)($data['loop_detection_threshold'] ?? 3)),
            llmMaxRetries: max(0, (int)($data['llm_max_retries'] ?? 3)),
            llmRetryDelaySec: max(1, (int)($data['llm_retry_delay_sec'] ?? 3)),
            globalSystemPrompt: (string)($data['global_system_prompt'] ?? '')
        );
    }

    public function toArray(): array
    {
        return [
            'subagent_max_steps' => $this->subagentMaxSteps,
            'root_max_steps' => $this->rootMaxSteps,
            'subagent_max_tokens' => $this->subagentMaxTokens,
            'loop_protection_enabled' => $this->loopProtectionEnabled,
            'loop_detection_threshold' => $this->loopDetectionThreshold,
            'llm_max_retries' => $this->llmMaxRetries,
            'llm_retry_delay_sec' => $this->llmRetryDelaySec,
            'global_system_prompt' => $this->globalSystemPrompt,
        ];
    }

    public function saveToDiskAndDb(?string $filePath = null, ?PDO $pdo = null): void
    {
        $filePath = $filePath ?? (getenv('DATA_DIR') ? getenv('DATA_DIR') . '/settings.json' : '/data/settings.json');
        $arr = $this->toArray();

        // 1. Save to JSON file
        @file_put_contents($filePath, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // 2. Save to SQLite database
        if ($pdo !== null) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
                $stmt = $pdo->prepare("INSERT INTO system_settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
                foreach ($arr as $k => $v) {
                    $stmt->execute([
                        ':k' => (string)$k,
                        ':v' => is_bool($v) ? ($v ? '1' : '0') : (string)$v,
                    ]);
                }
            } catch (\Throwable) {
                // Ignore DB write errors if any
            }
        }
    }
}
