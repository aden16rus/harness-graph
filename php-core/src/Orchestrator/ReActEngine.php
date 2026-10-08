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
                // If tools appear without preceding assistant turn in dialog, gather consecutive tools and synthesize assistant turn with tool_calls
                $consecutiveTools = [];
                $synthesizedToolCalls = [];
                $peek = $i;
                while ($peek < $count && ($dialog[$peek]['role'] ?? '') === 'tool') {
                    $tItem = $dialog[$peek];
                    $callId = (string)($tItem['call_id'] ?? ('call_' . $peek));
                    $name = (string)($tItem['name'] ?? 'tool');
                    $args = $tItem['args'] ?? [];
                    $synthesizedToolCalls[] = new ToolCall(id: $callId, name: $name, arguments: is_array($args) ? $args : []);
                    $consecutiveTools[] = $tItem;
                    $peek++;
                }

                $history[] = Message::assistant('', $synthesizedToolCalls);
                foreach ($consecutiveTools as $idxOffset => $tItem) {
                    $callId = (string)($tItem['call_id'] ?? ('call_' . ($i + $idxOffset)));
                    $name = (string)($tItem['name'] ?? 'tool');
                    $content = (string)($tItem['text'] ?? ($tItem['output'] ?? 'OK'));
                    $history[] = Message::tool($callId, $content, $name);
                }
                $i = $peek;
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

        $subagentMaxTokens = $this->settings?->subagentMaxTokens ?? 100000;
        $subagentContextTokens = $this->settings?->subagentContextTokens ?? 65536;
        $loopProtectionEnabled = $this->settings?->loopProtectionEnabled ?? true;
        $loopThreshold = $this->settings?->loopDetectionThreshold ?? 3;
        $toolCallHistory = [];
        $toolPathHistory = [];

        $step = $baseStep;
        $turnStep = 0;
        $finalAnswer = '';

        while ($turnStep < $effectiveMaxSteps) {
            $turnStep++;
            $step++;

            // Separate context window limit (threshold for history compaction)
            $effectiveContextLimit = $subagentContextTokens > 0 ? $subagentContextTokens : 65536;

            // Check if context compaction is needed
            $compactionResult = $this->contextManager->compactHistory($history, $effectiveContextLimit);
            if ($compactionResult['compacted']) {
                $history = $compactionResult['messages'];

                $compactionNotice = "📦 [Сжатие контекста]: Превышен лимит контекстного окна ({$compactionResult['before_tokens']}/{$effectiveContextLimit} токенов). Выполнено сжатие {$compactionResult['compacted_count']} сообщений предыстории (размер контекста снижен до ~{$compactionResult['after_tokens']} токенов).\n\n" . $compactionResult['summary'];

                $node->dialog[] = [
                    'role' => 'system',
                    'step' => $step,
                    'text' => $compactionNotice,
                    'is_compaction' => true,
                    'before_tokens' => $compactionResult['before_tokens'],
                    'after_tokens' => $compactionResult['after_tokens'],
                    'summary' => $compactionResult['summary'],
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ];

                $this->ipcClient->publishEvent('graph.context_compacted', $session->id, $node->id, [
                    'step' => $step,
                    'before_tokens' => $compactionResult['before_tokens'],
                    'after_tokens' => $compactionResult['after_tokens'],
                    'limit' => $effectiveContextLimit,
                    'summary' => $compactionResult['summary'],
                    'compacted_count' => $compactionResult['compacted_count'],
                    'message' => $compactionNotice,
                ]);

                $this->nodeRepo->save($node);
            }

            // Prepare messages payload for LLM
            $messagesPayload = array_map(static fn(Message $m) => $m->toArray(), $history);

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

            // 1. Current Context Window size for this node (prompt tokens of active call)
            $node->contextTokens = $promptTokens;

            // 2. Cumulative API tokens consumed across all calls
            $node->promptTokens += $promptTokens;
            $node->completionTokens += $completionTokens;
            $session->totalPromptTokens += $promptTokens;
            $session->totalCompletionTokens += $completionTokens;
            $this->sessionRepo->save($session);

            // Publish live real-time token telemetry event to graph (both context window and cumulative total)
            $this->ipcClient->publishEvent('graph.node_tokens', $session->id, $node->id, [
                'context_tokens' => $node->contextTokens,
                'prompt_tokens' => $node->promptTokens,
                'completion_tokens' => $node->completionTokens,
                'total_tokens' => $node->promptTokens + $node->completionTokens,
                'step' => $step,
            ]);

            // Sub-agent token budget check: if budget exceeded, lock tool calls and demand full summary report
            $nodeTotalTokens = $node->promptTokens + $node->completionTokens;
            if ($isSubAgent && $subagentMaxTokens > 0 && $nodeTotalTokens >= $subagentMaxTokens) {
                $tokenLimitNotice = "🛑 Достигнут лимит бюджета токенов саб-агента (израсходовано {$nodeTotalTokens} из {$subagentMaxTokens} токенов). Дальнейшие вызовы инструментов заблокированы. Предоставьте исчерпывающий итоговый отчет:";

                $budgetSummaryPrompt = $tokenLimitNotice . "\n"
                    . "1. Что конкретно было ВЫПОЛНЕНО (созданные/измененные файлы, реализованные модули, проведенные тесты).\n"
                    . "2. Что НЕ ВЫПОЛНЕНО или осталось недоделанным из запланированного.\n"
                    . "3. Текущий статус пунктов TODO листа (выполненные, в процессе, не начатые).\n"
                    . "4. Ожидаемый результат vs Фактический результат.\n"
                    . "5. Четкие рекомендации для вызывающего агента или пользователя по завершению задачи.";

                $this->ipcClient->publishEvent('graph.token_limit_exceeded', $session->id, $node->id, [
                    'tokens' => $nodeTotalTokens,
                    'limit' => $subagentMaxTokens,
                    'step' => $step,
                    'message' => $tokenLimitNotice,
                ]);

                $node->dialog[] = [
                    'role' => 'system',
                    'step' => $step,
                    'text' => $budgetSummaryPrompt,
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ];

                $history[] = Message::user($budgetSummaryPrompt);

                // Dedicated final summarization LLM call with NO TOOLS ALLOWED
                try {
                    $fittedBudgetSummary = $this->contextManager->fitContextWindow($history, $effectiveContextLimit);
                    $budgetSummaryPayload = array_map(static fn(Message $m) => $m->toArray(), $fittedBudgetSummary);

                    $budgetChatReq = [
                        'model' => $agent->model,
                        'messages' => $budgetSummaryPayload,
                        'tools' => null, // NO TOOLS ALLOWED - strictly summarization!
                        'temperature' => $agent->temperature,
                        'session_id' => $session->id,
                        'node_id' => $node->id,
                        'llm_profile_id' => $agent->llmProfileId,
                    ];

                    $summaryResp = $this->ipcClient->chatLlm($budgetChatReq);
                    $pTokens = (int)($summaryResp['prompt_tokens'] ?? 0);
                    $cTokens = (int)($summaryResp['completion_tokens'] ?? 0);
                    $node->promptTokens += $pTokens;
                    $node->completionTokens += $cTokens;
                    $session->totalPromptTokens += $pTokens;
                    $session->totalCompletionTokens += $cTokens;
                    $this->sessionRepo->save($session);

                    $summaryText = trim((string)($summaryResp['content'] ?? ''));
                    if ($summaryText !== '') {
                        $node->dialog[] = [
                            'role' => 'assistant',
                            'step' => $step,
                            'text' => $summaryText,
                            'timestamp' => gmdate('Y-m-d H:i:s'),
                        ];
                        $finalAnswer = $summaryText;
                    } else {
                        $finalAnswer = "🛑 Остановлено по исчерпанию бюджета токенов ({$nodeTotalTokens}/{$subagentMaxTokens}).";
                    }
                } catch (\Throwable) {
                    $finalAnswer = "🛑 Остановлено по исчерпанию бюджета токенов ({$nodeTotalTokens}/{$subagentMaxTokens}).";
                }

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

            // Record assistant turn in node dialog only when there is non-empty message content
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

            // Detect if current role is an inspector/reviewer/QA whose core purpose is reading and auditing
            $isInspectorRole = in_array(strtolower($agent->role), ['qa', 'techlead', 'backend_code_reviewer', 'frontend_code_reviewer'], true)
                || str_contains(strtolower($agent->role), 'review')
                || str_contains(strtolower($agent->role), 'qa')
                || str_contains(strtolower($agent->role), 'test')
                || str_contains(strtolower($agent->role), 'audit');

            // Anti-Loop Protection check when tools are called
            if ($loopProtectionEnabled && !empty($parsedToolCalls)) {
                $turnSignatures = [];
                $callPaths = [];

                foreach ($parsedToolCalls as $tc) {
                    $argsJson = json_encode($tc->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $turnSignatures[] = $tc->name . ':' . md5((string)$argsJson);
                    if (isset($tc->arguments['path']) && trim((string)$tc->arguments['path']) !== '') {
                        $pStr = trim((string)$tc->arguments['path']);
                        $rangeKey = '';
                        if ($tc->name === 'read_file') {
                            $start = $tc->arguments['start_line'] ?? ($tc->arguments['from_line'] ?? ($tc->arguments['offset'] ?? '1'));
                            $end = $tc->arguments['end_line'] ?? ($tc->arguments['to_line'] ?? ($tc->arguments['limit'] ?? ($tc->arguments['max_lines'] ?? 'all')));
                            $rangeKey = "@L{$start}-{$end}";
                        }
                        $callPaths[] = $tc->name . ':' . $pStr . $rangeKey;
                    }
                }
                $currentTurnSignature = implode('|', $turnSignatures);
                $toolCallHistory[] = $currentTurnSignature;
                if (!empty($callPaths)) {
                    $toolPathHistory[] = implode('|', $callPaths);
                }

                // Check 1: Consecutive identical tool calls with identical arguments
                $historyLen = count($toolCallHistory);
                $consecutiveCount = 0;
                for ($i = $historyLen - 1; $i >= 0; $i--) {
                    if ($toolCallHistory[$i] === $currentTurnSignature) {
                        $consecutiveCount++;
                    } else {
                        break;
                    }
                }

                // Check 2: Multi-period cycle detection (periods P in 2..6)
                $cycleDetected = false;
                $cyclePeriod = 0;
                $maxPeriod = min(6, (int)floor($historyLen / $loopThreshold));
                for ($p = 2; $p <= $maxPeriod; $p++) {
                    $reqLen = $p * $loopThreshold;
                    if ($historyLen >= $reqLen) {
                        $pMatches = true;
                        for ($k = 0; $k < $reqLen; $k++) {
                            if ($toolCallHistory[$historyLen - 1 - $k] !== $toolCallHistory[$historyLen - 1 - ($k % $p)]) {
                                $pMatches = false;
                                break;
                            }
                        }
                        if ($pMatches) {
                            $cycleDetected = true;
                            $cyclePeriod = $p;
                            break;
                        }
                    }
                }

                // Check 3: Repeated inspection of the SAME path without changes
                // If the agent reads DIFFERENT files or inspects different dirs, it is exploring, not looping!
                $reReadingSamePath = false;
                $repeatedPathName = '';
                if (!empty($toolPathHistory) && count($toolPathHistory) >= 6) {
                    $recentPaths = array_slice($toolPathHistory, -8);
                    $pathCounts = array_count_values($recentPaths);
                    foreach ($pathCounts as $pathKey => $pCount) {
                        if ($pCount >= 4) {
                            $reReadingSamePath = true;
                            $repeatedPathName = $pathKey;
                            break;
                        }
                    }
                }

                // For inspector roles (QA, reviewer), reading different files is completely normal and never a loop.
                $shouldTriggerLoop = ($consecutiveCount >= $loopThreshold) || $cycleDetected || ($reReadingSamePath && !$isInspectorRole);

                if ($shouldTriggerLoop) {
                    $toolNamesList = implode(', ', array_map(fn($tc) => $tc->name, $parsedToolCalls));
                    if ($cycleDetected) {
                        $loopReason = "Обнаружен циклический паттерн вызова инструментов (период {$cyclePeriod}) {$loopThreshold} раз подряд.";
                    } elseif ($reReadingSamePath) {
                        $loopReason = "Обнаружено зацикливание на многократном повторном чтении одного и того же пути [{$repeatedPathName}] 4+ раза подряд без продвижения вперед.";
                    } else {
                        $loopReason = "Инструмент(ы) [{$toolNamesList}] вызван(ы) с идентичными параметрами {$consecutiveCount} раз подряд без продвижения к решению.";
                    }

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
            $agentTypeStr = $isSubAgent ? "саб-агента (роль: {$node->role}, глубина {$node->depth})" : "главного агента";
            $step++;

            $summaryPrompt = "⚠️ ВНИМАНИЕ: Вы достигли лимита шагов выполнения ({$effectiveMaxSteps}) для {$agentTypeStr}.\n"
                . "Вызов новых инструментов заблокирован. Пожалуйста, предоставьте исчерпывающий итоговый отчет и суммаризацию выполненного:\n"
                . "1. Что конкретно было сделано (созданные/измененные файлы, выполненные проверки).\n"
                . "2. Текущий статус пунктов TODO листа (какие выполнены, какие в процессе, какие не начаты).\n"
                . "3. Ожидаемый результат vs Фактический результат.\n"
                . "4. Причина остановки (исчерпан лимит шагов) и что осталось доделать.\n"
                . "5. Четкие рекомендации по следующим шагам для вызывающего агента.";

            $node->dialog[] = [
                'role' => 'system',
                'step' => $step,
                'text' => $summaryPrompt,
                'timestamp' => gmdate('Y-m-d H:i:s'),
            ];

            $history[] = Message::user($summaryPrompt);

            // Execute a dedicated final summarization call without any tools allowed
            try {
                $fittedSummaryMessages = $this->contextManager->fitContextWindow($history, $effectiveContextLimit);
                $summaryMessagesPayload = array_map(static fn(Message $m) => $m->toArray(), $fittedSummaryMessages);

                $summaryChatReq = [
                    'model' => $agent->model,
                    'messages' => $summaryMessagesPayload,
                    'tools' => null, // NO TOOLS ALLOWED - strictly summarization!
                    'temperature' => $agent->temperature,
                    'session_id' => $session->id,
                    'node_id' => $node->id,
                    'llm_profile_id' => $agent->llmProfileId,
                ];

                $summaryResponse = $this->ipcClient->chatLlm($summaryChatReq);
                $pTokens = (int)($summaryResponse['prompt_tokens'] ?? 0);
                $cTokens = (int)($summaryResponse['completion_tokens'] ?? 0);
                $node->promptTokens += $pTokens;
                $node->completionTokens += $cTokens;
                $session->totalPromptTokens += $pTokens;
                $session->totalCompletionTokens += $cTokens;
                $this->sessionRepo->save($session);

                $summaryText = trim((string)($summaryResponse['content'] ?? ''));
                if ($summaryText !== '') {
                    $finalAnswer = $summaryText;
                } else {
                    $finalAnswer = "Execution reached maximum step limit of {$effectiveMaxSteps} ({$agentTypeStr}).";
                }
            } catch (\Throwable) {
                $finalAnswer = "Execution reached maximum step limit of {$effectiveMaxSteps} ({$agentTypeStr}).";
            }
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
