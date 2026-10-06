<?php
declare(strict_types=1);

namespace Harness\Domain\ValueObject;

final readonly class TokenUsage
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0
    ) {}

    public function add(self $other): self
    {
        return new self(
            promptTokens: $this->promptTokens + $other->promptTokens,
            completionTokens: $this->completionTokens + $other->completionTokens,
            totalTokens: $this->totalTokens + $other->totalTokens
        );
    }

    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
}
