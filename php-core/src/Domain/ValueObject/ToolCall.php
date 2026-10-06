<?php
declare(strict_types=1);

namespace Harness\Domain\ValueObject;

final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public string $type = 'function'
    ) {}

    public function toArray(): array
    {
        $args = empty($this->arguments) ? new \stdClass() : $this->arguments;
        return [
            'id' => $this->id,
            'type' => $this->type,
            'function' => [
                'name' => $this->name,
                'arguments' => json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
            ],
        ];
    }

    public static function fromArray(array $data): self
    {
        $fn = $data['function'] ?? [];
        $name = $fn['name'] ?? '';
        $argsRaw = $fn['arguments'] ?? '{}';
        $args = is_string($argsRaw) ? (json_decode($argsRaw, true) ?? []) : (array)$argsRaw;

        return new self(
            id: $data['id'] ?? uniqid('call_', true),
            name: $name,
            arguments: $args,
            type: $data['type'] ?? 'function'
        );
    }
}
