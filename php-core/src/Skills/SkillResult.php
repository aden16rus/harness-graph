<?php
declare(strict_types=1);

namespace Harness\Skills;

final readonly class SkillResult
{
    public function __construct(
        public bool $success,
        public string $output,
        public ?string $error = null,
        public array $artifacts = []
    ) {}

    public static function ok(string $output, array $artifacts = []): self
    {
        return new self(success: true, output: $output, error: null, artifacts: $artifacts);
    }

    public static function fail(string $error, string $output = ''): self
    {
        return new self(success: false, output: $output, error: $error);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'output' => $this->output,
            'error' => $this->error,
            'artifacts' => $this->artifacts,
        ];
    }

    public function toMessageContent(): string
    {
        if (!$this->success) {
            $msg = "ERROR: " . ($this->error ?? 'Operation failed');
            if ($this->output !== '') {
                $msg .= "\nOutput:\n" . $this->output;
            }
            return $msg;
        }

        return $this->output !== '' ? $this->output : 'Success';
    }
}
