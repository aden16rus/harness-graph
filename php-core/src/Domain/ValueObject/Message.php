<?php
declare(strict_types=1);

namespace Harness\Domain\ValueObject;

final readonly class Message
{
    /**
     * @param array<ToolCall> $toolCalls
     */
    public function __construct(
        public string $role,
        public string $content,
        public ?string $name = null,
        public ?string $toolCallId = null,
        public array $toolCalls = []
    ) {}

    public function toArray(): array
    {
        $data = [
            'role' => $this->role,
            'content' => ($this->role === 'assistant' && !empty($this->toolCalls) && $this->content === '') ? null : $this->content,
        ];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }

        if ($this->toolCallId !== null) {
            $data['tool_call_id'] = $this->toolCallId;
        }

        if (!empty($this->toolCalls)) {
            $data['tool_calls'] = array_map(
                static fn(ToolCall $tc) => $tc->toArray(),
                $this->toolCalls
            );
        }

        return $data;
    }

    public static function system(string $content): self
    {
        return new self(role: 'system', content: $content);
    }

    public static function user(string $content): self
    {
        return new self(role: 'user', content: $content);
    }

    public static function assistant(string $content, array $toolCalls = []): self
    {
        return new self(role: 'assistant', content: $content, toolCalls: $toolCalls);
    }

    public static function tool(string $toolCallId, string $content, ?string $name = null): self
    {
        return new self(role: 'tool', content: $content, name: $name, toolCallId: $toolCallId);
    }
}
