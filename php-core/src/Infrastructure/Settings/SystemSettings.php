<?php
declare(strict_types=1);

namespace Harness\Infrastructure\Settings;

final class SystemSettings
{
    public function __construct(
        public int $subagentMaxSteps = 15,
        public int $rootMaxSteps = 25,
        public int $subagentMaxTokens = 50000,
        public bool $loopProtectionEnabled = true,
        public int $loopDetectionThreshold = 3,
        public int $llmMaxRetries = 3,
        public int $llmRetryDelaySec = 3
    ) {}

    public static function load(?string $filePath = null): self
    {
        $filePath = $filePath ?? (getenv('DATA_DIR') ? getenv('DATA_DIR') . '/settings.json' : '/data/settings.json');
        $data = [];
        if (file_exists($filePath)) {
            $raw = @file_get_contents($filePath);
            if ($raw !== false) {
                $data = json_decode($raw, true) ?: [];
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

        return new self(
            subagentMaxSteps: max(1, $subagentSteps),
            rootMaxSteps: max(1, $rootSteps),
            subagentMaxTokens: max(0, $subagentTokens),
            loopProtectionEnabled: $loopProt,
            loopDetectionThreshold: max(2, $loopThresh),
            llmMaxRetries: max(0, $llmRetries),
            llmRetryDelaySec: max(1, $llmDelay)
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
        ];
    }
}
