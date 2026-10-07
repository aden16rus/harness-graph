<?php
declare(strict_types=1);

namespace Harness\Orchestrator;

use Harness\Context\ContextManager;
use Harness\Domain\Entity\Agent;
use Harness\Domain\Entity\ExecutionNode;
use Harness\Domain\Entity\Project;
use Harness\Domain\Entity\Session;
use Harness\Domain\Enum\NodeStatus;
use Harness\Domain\Repository\AgentRepositoryInterface;
use Harness\Domain\Repository\ExecutionNodeRepositoryInterface;
use Harness\Domain\Repository\SessionRepositoryInterface;
use Harness\Domain\ValueObject\Message;
use Harness\Domain\ValueObject\ToolCall;
use Harness\Infrastructure\IPC\JsonRpcClient;
use Harness\Infrastructure\Settings\SystemSettings;
use Harness\Skills\PermissionPolicy;
use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillRegistry;
use Harness\Skills\SkillResult;

final class ReActEngine
{
    public const int DEFAULT_MAX_STEPS = 25;

    public function __construct(
        private readonly JsonRpcClient $ipcClient,
        private readonly ContextManager $contextManager,
        private readonly SkillRegistry $skillRegistry,
        private readonly PermissionPolicy $permissionPolicy,
        private readonly ExecutionNodeRepositoryInterface $nodeRepo,
        private readonly SessionRepositoryInterface $sessionRepo,
        private readonly SubAgentManager $subAgentManager,
        private readonly ?AgentRepositoryInterface $agentRepo = null,
        private readonly int $maxSteps = self::DEFAULT_MAX_STEPS,
        private readonly ?SystemSettings $settings = null
    ) {
        $this->subAgentManager->setEngine($this);
    }

    /**
     * Reconstructs full conversation message history from a node's dialog.
     * Preserves all user prompts, assistant turns, tool calls and observations.
     *
     * @param array<array<string, mixed>> $dialog
     * @return array<Message>
     */
    public function rebuildHistoryFromDialog(array $dialog, string $currentSystemPrompt): array
    {
        $history = [Message::system($currentSystemPrompt)];
        $count = count($dialog);
        $i = 0;

        while ($i < $count) {
            $item = $dialog[$i];
            $role = (string)($item['role'] ?? '');
            $text = (string)($item['text'] ?? '');

            if ($role === 'system') {
                // If it's a runtime system alert, include as a user notice
                if ($i > 0 && (str_starts_with($text, '🛑') || str_starts_with($text, '⚠️') || str_starts_with($text, '🔁'))) {
                    $history[] = Message::user("[System Notice: {$text}]");
                }
                $i++;
                continue;
            }

            if ($role === 'user') {
                $history[] = Message::user($text);
                $i++;
                continue;
            }

            if ($role === 'thinking') {
                // Internal reasoning, skip for standard API message history
                $i++;
                continue;
            }

            if ($role === 'assistant') {
                // Look ahead to check if this assistant turn triggered tool calls
                $toolCalls = [];
                $peek = $i + 1;
                while ($peek < $count && ($dialog[$peek]['role'] ?? '') === 'tool') {
                    $tItem = $dialog[$peek];
                    $callId = (string)($tItem['call_id'] ?? ('call_' . $peek));
                    $name = (string)($tItem['name'] ?? 'tool');
                    $args = $tItem['args'] ?? [];
                    $toolCalls[] = new ToolCall(
                        id: $callId,
                        name: $name,
                        arguments: is_array($args) ? $args : []
                    );
                    $peek++;
                }

                $history[] = Message::assistant($text, $toolCalls);
                $i++;
                continue;
            }

            if ($role === 'tool') {
                $callId = (string)($item['call_id'] ?? ('call_' . $i));
                $name = (string)($item['name'] ?? 'tool');
                $content = (string)($item['text'] ?? ($item['output'] ?? 'OK'));
                $history[] = Message::tool($callId, $content, $name);
                $i++;
                continue;
            }

            $i++;
        }

        return $history;
    }

    public function executeNode(
        ExecutionNode $node,
        Agent $agent,
        string $taskPrompt,
        Project $project,
        Session $session,
        bool $isContinuation = false
    ): ExecutionNode {
        $context = new SkillExecutionContext(
            session: $session,
            node: $node,
            agent: $agent,
            project: $project,
            ipcClient: $this->ipcClient,
            subAgentManager: $this->subAgentManager
        );

        $systemPrompt = $this->contextManager->buildSystemPrompt($agent, $project);
        $tools = $this->skillRegistry->getToolDefinitionsForAgent($agent, $this->permissionPolicy, $this->agentRepo);

        // Calculate step counter base from existing dialog
        $baseStep = 0;
        if (!empty($node->dialog)) {
            foreach ($node->dialog as $d) {
                if (!empty($d['step'])) {
                    $baseStep = max($baseStep, (int)$d['step']);
                }
            }
        }

        // If dialog already has messages, rebuild full conversation history so context is completely preserved!
        if (!empty($node->dialog)) {
            $history = $this->rebuildHistoryFromDialog($node->dialog, $systemPrompt);
        } else {
            // First time execution: initialize dialog with system and user task
            $node->dialog[] = [
                'role' => 'system',
                'step' => 0,
                'text' => $systemPrompt,
                'timestamp' => gmdate('Y-m-d H:i:s'),
            ];
            $node->dialog[] = [
                'role' => 'user',
                'step' => 0,
                'text' => $taskPrompt,
                'timestamp' => gmdate('Y-m-d H:i:s'),
            ];
            $this->nodeRepo->save($node);

            $history = [
                Message::system($systemPrompt),
                Message::user($taskPrompt),
            ];
        }

        // Determine step limit: subagents use subagentMaxSteps, root agent uses rootMaxSteps
        $isSubAgent = ($node->depth > 0 || !empty($node->parentNodeId));
        $effectiveMaxSteps = $isSubAgent
            ? ($this->settings?->subagentMaxSteps ?? 15)
            : ($this->settings?->rootMaxSteps ?? $this->maxSteps);

        $subagentMaxTokens = $this->settings?->subagentMaxTokens ?? 50000;
        $loopProtectionEnabled = $this->settings?->loopProtectionEnabled ?? true;
        $loopThreshold = $this->settings?->loopDetectionThreshold ?? 3;
        $toolCallHistory = [];

        $step = $baseStep;
        $turnStep = 0;
        $finalAnswer = '';

        while ($turnStep < $effectiveMaxSteps) {
            $turnStep++;
            $step++;

            // Fit context window if needed
            $fittedMessages = $this->contextManager->fitContextWindow($history, $agent->tokenLimit);

            // Prepare messages payload for LLM
            $messagesPayload = array_map(static fn(Message $m) => $m->toArray(), $fittedMessages);

            $chatReq = [
                'model' => $agent->model,
                'messages' => $messagesPayload,
                'tools' => !empty($tools) ? $tools : null,
                'temperature' => $agent->temperature,
                'session_id' => $session->id,
                'node_id' => $node->id,
                'llm_profile_id' => $agent->llmProfileId,
            ];

            // Call LLM with retry mechanism and pause backoff on errors
            $maxRetries = $this->settings?->llmMaxRetries ?? 3;
            $retryDelaySec = $this->settings?->llmRetryDelaySec ?? 3;
            $llmResponse = null;
            $lastLlmError = null;

            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                if ($attempt > 0) {
                    $this->ipcClient->publishEvent('graph.llm_retry', $session->id, $node->id, [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'delay_sec' => $retryDelaySec,
                        'reason' => $lastLlmError,
                        'step' => $step,
                    ]);
                    sleep($retryDelaySec);
                }

                try {
                    $llmResponse = $this->ipcClient->chatLlm($chatReq);
                    $content = (string)($llmResponse['content'] ?? '');
                    $reasoningContent = (string)($llmResponse['reasoning_content'] ?? '');

                    // Fallback: extract <think> from content if model put it inside content
                    if ($reasoningContent === '' && str_contains($content, '<think>') && str_contains($content, '</think>')) {
                        $start = strpos($content, '<think>');
                        $end = strpos($content, '</think>');
                        if ($start !== false && $end !== false && $end > $start) {
                            $reasoningContent = trim(substr($content, $start + 7, $end - ($start + 7)));
                            $content = trim(substr($content, 0, $start) . substr($content, $end + 8));
                        }
                    }

                    // Transient rate limit or proxy error check
                    if ((str_contains($content, '429') || str_contains($content, 'RESOURCE_EXHAUSTED') || str_contains($content, '[Proxy Error:')) && $attempt < $maxRetries) {
                        $lastLlmError = "Rate limit / proxy response: " . substr($content, 0, 120);
                        continue;
                    }

                    $lastLlmError = null;
                    break;
                } catch (\Throwable $e) {
                    $lastLlmError = $e->getMessage();
                    if ($attempt < $maxRetries) {
                        continue;
                    }
                    throw $e;
                }
            }

            $content = (string)($llmResponse['content'] ?? '');
            $reasoningContent = (string)($llmResponse['reasoning_content'] ?? '');
            if ($reasoningContent === '' && str_contains($content, '<think>') && str_contains($content, '</think>')) {
                $start = strpos($content, '<think>');
                $end = strpos($content, '</think>');
                if ($start !== false && $end !== false && $end > $start) {
                    $reasoningContent = trim(substr($content, $start + 7, $end - ($start + 7)));
                    $content = trim(substr($content, 0, $start) . substr($content, $end + 8));
                }
            }

            $rawToolCalls = (array)($llmResponse['tool_calls'] ?? []);
            $promptTokens = (int)($llmResponse['prompt_tokens'] ?? 0);
            $completionTokens = (int)($llmResponse['completion_tokens'] ?? 0);

            // Aggregate tokens
            $node->promptTokens += $promptTokens;
            $node->completionTokens += $completionTokens;
            $session->totalPromptTokens += $promptTokens;
            $session->totalCompletionTokens += $completionTokens;
            $this->sessionRepo->save($session);

            // Publish live real-time token telemetry event to graph
            $this->ipcClient->publishEvent('graph.node_tokens', $session->id, $node->id, [
                'prompt_tokens' => $node->promptTokens,
                'completion_tokens' => $node->completionTokens,
                'step' => $step,
            ]);

            // Sub-agent token limit check
            $nodeTotalTokens = $node->promptTokens + $node->completionTokens;
            if ($isSubAgent && $subagentMaxTokens > 0 && $nodeTotalTokens >= $subagentMaxTokens) {
                $tokenLimitError = "🛑 Превышен лимит токенов саб-агента: израсходовано {$nodeTotalTokens} токенов (установленный лимит: {$subagentMaxTokens}). Выполнение шагов прервано.";
                $this->ipcClient->publishEvent('graph.token_limit_exceeded', $session->id, $node->id, [
                    'tokens' => $nodeTotalTokens,
                    'limit' => $subagentMaxTokens,
                    'step' => $step,
                    'message' => $tokenLimitError,
                ]);
                $node->dialog[] = [
                    'role' => 'system',
                    'step' => $step,
                    'text' => $tokenLimitError,
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ];
                $finalAnswer = $tokenLimitError;
                break;
            }

            $parsedToolCalls = [];
            foreach ($rawToolCalls as $tc) {
                $parsedToolCalls[] = ToolCall::fromArray($tc);
            }

            // Record reasoning in dialog if present
            if ($reasoningContent !== '') {
                $node->dialog[] = [
                    'role' => 'thinking',
                    'step' => $step,
                    'text' => $reasoningContent,
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ];
                $this->ipcClient->publishEvent('graph.node_reasoning', $session->id, $node->id, [
                    'text' => $reasoningContent,
                    'role' => 'thinking',
                    'step' => $step,
                ]);
            }

            // Record assistant turn in node dialog
            if ($content !== '') {
                $node->dialog[] = [
                    'role' => 'assistant',
                    'step' => $step,
                    'text' => $content,
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ];
            }

            // Append assistant response to history
            $history[] = Message::assistant($content, $parsedToolCalls);

            // If no tool calls were requested, LLM provided final answer or error
            if (empty($parsedToolCalls)) {
                $finalAnswer = $content;
                if (str_contains($content, '[Proxy Error:') || str_contains($content, 'RESOURCE_EXHAUSTED') || str_contains($content, 'HTTP 429')) {
                    $node->status = NodeStatus::FAILED;
                    $node->outputResult = $finalAnswer;
                    $session->status = \Harness\Domain\Enum\SessionStatus::FAILED;
                    $this->sessionRepo->save($session);
                    $this->nodeRepo->save($node);
                    $this->ipcClient->publishEvent('graph.node_failed', $session->id, $node->id, [
                        'error' => $finalAnswer,
                        'duration_ms' => $node->durationMs,
                    ]);
                    return $node;
                }
                break;
            }

            // Anti-Loop Protection check when tools are called
            if ($loopProtectionEnabled && !empty($parsedToolCalls)) {
                $turnSignatures = [];
                foreach ($parsedToolCalls as $tc) {
                    $argsJson = json_encode($tc->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $turnSignatures[] = $tc->name . ':' . md5((string)$argsJson);
                }
                $currentTurnSignature = implode('|', $turnSignatures);
                $toolCallHistory[] = $currentTurnSignature;

                // Check 1: Consecutive identical tool calls
                $historyLen = count($toolCallHistory);
                $consecutiveCount = 0;
                for ($i = $historyLen - 1; $i >= 0; $i--) {
                    if ($toolCallHistory[$i] === $currentTurnSignature) {
                        $consecutiveCount++;
                    } else {
                        break;
                    }
                }

                // Check 2: Cycle detection (e.g. A -> B -> A -> B)
                $cycleDetected = false;
                $cyclePeriod = 0;
                if ($historyLen >= 4 && $historyLen >= $loopThreshold * 2) {
                    $p2Matches = true;
                    for ($k = 0; $k < $loopThreshold * 2; $k++) {
                        if ($toolCallHistory[$historyLen - 1 - $k] !== $toolCallHistory[$historyLen - 1 - ($k % 2)]) {
                            $p2Matches = false;
                            break;
                        }
                    }
                    if ($p2Matches && $toolCallHistory[$historyLen - 1] !== $toolCallHistory[$historyLen - 2]) {
                        $cycleDetected = true;
                        $cyclePeriod = 2;
                    }
                }

                if ($consecutiveCount >= $loopThreshold || $cycleDetected) {
                    $toolNamesList = implode(', ', array_map(fn($tc) => $tc->name, $parsedToolCalls));
                    $loopReason = $cycleDetected
                        ? "Обнаружен циклический паттерн вызова инструментов (период {$cyclePeriod}) {$loopThreshold} раз подряд."
                        : "Инструмент(ы) [{$toolNamesList}] вызван(ы) с идентичными параметрами {$consecutiveCount} раз подряд без продвижения к решению.";

                    $this->ipcClient->publishEvent('graph.loop_detected', $session->id, $node->id, [
                        'tools' => $toolNamesList,
                        'repeats' => $consecutiveCount,
                        'reason' => $loopReason,
                        'step' => $step,
                    ]);

                    $node->dialog[] = [
                        'role' => 'system',
                        'step' => $step,
                        'text' => "🛑 Защита от зацикливания: {$loopReason} Выполнение шагов прервано.",
                        'timestamp' => gmdate('Y-m-d H:i:s'),
                    ];

                    $finalAnswer = "🛑 Остановлено защитой от зацикливания: {$loopReason}";
                    break;
                } elseif ($consecutiveCount === ($loopThreshold - 1) && $loopThreshold > 2) {
                    $toolNamesList = implode(', ', array_map(fn($tc) => $tc->name, $parsedToolCalls));
                    $history[] = Message::user(
                        "⚠️ ВНИМАНИЕ: Зафиксировано повторение вызова [{$toolNamesList}] с идентичными параметрами ({$consecutiveCount} раз). Не повторяйте этот вызов! Смените тактику, используйте другие инструменты или дайте итоговый ответ пользователю."
                    );
                    $node->dialog[] = [
                        'role' => 'system',
                        'step' => $step,
                        'text' => "⚠️ Предупреждение о потенциальном зацикливании: инструмент [{$toolNamesList}] повторяется {$consecutiveCount} раз подряд.",
                        'timestamp' => gmdate('Y-m-d H:i:s'),
                    ];
                }
            }

            // Execute each requested tool call
            foreach ($parsedToolCalls as $toolCall) {
                $toolName = $toolCall->name;
                $toolArgs = $toolCall->arguments;

                $node->status = NodeStatus::CALLING_TOOL;
                $node->activeTool = $toolName;
                $this->nodeRepo->save($node);

                $this->ipcClient->publishEvent('graph.tool_call_started', $session->id, $node->id, [
                    'tool' => $toolName,
                    'arguments' => $toolArgs,
                    'call_id' => $toolCall->id,
                    'step' => $step,
                ]);

                $toolStart = microtime(true);
                $skillResult = null;

                try {
                    $this->permissionPolicy->assertAllowed($agent, $toolName);
                    $skill = $this->skillRegistry->get($toolName);
                    if ($skill === null) {
                        $skillResult = SkillResult::fail("Tool '{$toolName}' is not registered");
                    } else {
                        $skillResult = $skill->execute($toolArgs, $context);
                    }
                } catch (\Throwable $e) {
                    $skillResult = SkillResult::fail("Tool execution exception: " . $e->getMessage());
                }

                $toolDurationMs = (int)((microtime(true) - $toolStart) * 1000);

                $node->toolCalls[] = [
                    'id' => $toolCall->id,
                    'name' => $toolName,
                    'args' => $toolArgs,
                    'status' => $skillResult->success ? 'ok' : 'fail',
                    'output' => $skillResult->output,
                    'error' => $skillResult->error,
                    'duration_ms' => $toolDurationMs,
                    'step' => $step,
                ];

                $node->dialog[] = [
                    'role' => 'tool',
                    'step' => $step,
                    'name' => $toolName,
                    'call_id' => $toolCall->id,
                    'args' => $toolArgs,
                    'status' => $skillResult->success ? 'ok' : 'fail',
                    'output' => $skillResult->output,
                    'error' => $skillResult->error,
                    'duration_ms' => $toolDurationMs,
                    'text' => $skillResult->toMessageContent(),
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ];

                $this->ipcClient->publishEvent('graph.tool_call_finished', $session->id, $node->id, [
                    'tool' => $toolName,
                    'call_id' => $toolCall->id,
                    'arguments' => $toolArgs,
                    'success' => $skillResult->success,
                    'output' => $skillResult->output,
                    'error' => $skillResult->error,
                    'duration_ms' => $toolDurationMs,
                    'step' => $step,
                ]);

                $history[] = Message::tool(
                    toolCallId: $toolCall->id,
                    content: $skillResult->toMessageContent(),
                    name: $toolName
                );

                $node->activeTool = null;
                $node->status = NodeStatus::ACTIVE;
                $this->nodeRepo->save($node);
            }
        }

        if ($finalAnswer === '' && $turnStep >= $effectiveMaxSteps) {
            $agentTypeStr = $isSubAgent ? "саб-агента (глубина {$node->depth})" : "главного агента";
            $finalAnswer = "Execution reached maximum step limit of {$effectiveMaxSteps} ({$agentTypeStr}).";
        }

        $node->outputResult = $finalAnswer;
        if (!empty($finalAnswer)) {
            $lastDialog = !empty($node->dialog) ? end($node->dialog) : null;
            if (!$lastDialog || $lastDialog['text'] !== $finalAnswer) {
                $node->dialog[] = [
                    'role' => 'assistant',
                    'step' => $step,
                    'text' => $finalAnswer,
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ];
            }
        }
        $node->finishedAt = gmdate('Y-m-d H:i:s');
        $this->nodeRepo->save($node);
        return $node;
    }
}
