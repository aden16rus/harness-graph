<?php
declare(strict_types=1);

namespace Harness\Skills\Builtin;

use Harness\Domain\Enum\NodeStatus;
use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillInterface;
use Harness\Skills\SkillResult;

final class AskHumanExpertSkill implements SkillInterface
{
    public function getName(): string
    {
        return 'ask_human_expert';
    }

    public function getDescription(): string
    {
        return 'Pauses current agent reasoning and asks a human expert for guidance, clarification, or confirmation.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The specific question or decision requested from the human.',
                ],
                'options' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional list of recommended choices or alternatives.',
                ],
            ],
            'required' => ['question'],
        ];
    }

    public function execute(array $params, SkillExecutionContext $context): SkillResult
    {
        $question = trim((string)($params['question'] ?? ''));
        if ($question === '') {
            return SkillResult::fail('Question cannot be empty');
        }

        $options = (array)($params['options'] ?? []);

        // 1. Update node status to WAITING_HUMAN and publish event
        $context->node->status = NodeStatus::WAITING_HUMAN;
        $context->ipcClient->publishEvent('graph.human_required', $context->session->id, $context->node->id, [
            'question' => $question,
            'options' => $options,
            'status' => NodeStatus::WAITING_HUMAN->value,
        ]);

        // 2. Block until operator responds via Go Engine
        try {
            $answer = $context->ipcClient->askHuman(
                $context->session->id,
                $context->node->id,
                $question,
                $options,
                600000 // 10 min timeout
            );
        } catch (\Throwable $e) {
            $context->node->status = NodeStatus::ACTIVE;
            return SkillResult::fail("Error awaiting human answer: " . $e->getMessage());
        }

        // 3. Resume node status to ACTIVE
        $context->node->status = NodeStatus::ACTIVE;
        $context->ipcClient->publishEvent('graph.node_resumed', $context->session->id, $context->node->id, [
            'status' => NodeStatus::ACTIVE->value,
            'answer' => $answer,
        ]);

        return SkillResult::ok("Human Expert Answer: {$answer}", ['answer' => $answer]);
    }
}
