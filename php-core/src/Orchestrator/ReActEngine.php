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
        private readonly int $maxSteps = self::DEFAULT_MAX_STEPS
    ) {
        $this->subAgentManager->setEngine($this);
    }

    public function executeNode(
        ExecutionNode $node,
        Agent $agent,
        string $taskPrompt,
        Project $project,
        Session $session
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

        // Record initial roles in dialog if empty
        if (empty($node->dialog)) {
            $node->dialog[] = [
                'role' => 'system',
                'text' => $systemPrompt,
                'timestamp' => gmdate('Y-m-d H:i:s'),
            ];
            $node->dialog[] = [
                'role' => 'user',
                'text' => $taskPrompt,
                'timestamp' => gmdate('Y-m-d H:i:s'),
            ];
            $this->nodeRepo->save($node);
        }

        $history = [
            Message::system($systemPrompt),
            Message::user($taskPrompt),
        ];

        $step = 0;
        $finalAnswer = '';

        while ($step < $this->maxSteps) {
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

            // Call LLM through Go Engine proxy
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

            // Handle transient 429 rate limit quota bursts with automatic backoff retry
            if (str_contains($content, '429') || str_contains($content, 'RESOURCE_EXHAUSTED')) {
                usleep(3000000); // 3 seconds backoff
                $llmResponse = $this->ipcClient->chatLlm($chatReq);
                $content = (string)($llmResponse['content'] ?? '');
                $reasoningContent = (string)($llmResponse['reasoning_content'] ?? $reasoningContent);
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

            $parsedToolCalls = [];
            foreach ($rawToolCalls as $tc) {
                $parsedToolCalls[] = ToolCall::fromArray($tc);
            }

            // Record reasoning in dialog if present
            if ($reasoningContent !== '') {
                $node->dialog[] = [
                    'role' => 'thinking',
                    'text' => $reasoningContent,
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ];
                $this->ipcClient->publishEvent('graph.node_reasoning', $session->id, $node->id, [
                    'text' => $reasoningContent,
                    'role' => 'thinking',
                ]);
            }

            // Record assistant turn in node dialog
            if ($content !== '') {
                $node->dialog[] = [
                    'role' => 'assistant',
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

            // Execute each requested tool call
            foreach ($parsedToolCalls as $toolCall) {
                $toolName = $toolCall->name;
                $toolArgs = $toolCall->arguments;

                $node->status = NodeStatus::CALLING_TOOL;
                $node->activeTool = $toolName;
                $this->nodeRepo->save($node);

                // Publish tool_call_started event
                $this->ipcClient->publishEvent('graph.tool_call_started', $session->id, $node->id, [
                    'tool' => $toolName,
                    'arguments' => $toolArgs,
                    'call_id' => $toolCall->id,
                ]);

                $toolStart = microtime(true);
                $skillResult = null;

                try {
                    // Check permissions
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

                // Record tool execution in node
                $node->toolCalls[] = [
                    'id' => $toolCall->id,
                    'name' => $toolName,
                    'args' => $toolArgs,
                    'status' => $skillResult->success ? 'ok' : 'fail',
                    'output' => $skillResult->output,
                    'error' => $skillResult->error,
                    'duration_ms' => $toolDurationMs,
                ];

                // Record tool response in dialog
                $node->dialog[] = [
                    'role' => 'tool',
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

                // Publish tool_call_finished event
                $this->ipcClient->publishEvent('graph.tool_call_finished', $session->id, $node->id, [
                    'tool' => $toolName,
                    'call_id' => $toolCall->id,
                    'arguments' => $toolArgs,
                    'success' => $skillResult->success,
                    'output' => $skillResult->output,
                    'error' => $skillResult->error,
                    'duration_ms' => $toolDurationMs,
                ]);

                // Append observation back into conversation history
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

        if ($finalAnswer === '' && $step >= $this->maxSteps) {
            $finalAnswer = "Execution reached maximum step limit of {$this->maxSteps}.";
        }

        $node->outputResult = $finalAnswer;
        if (!empty($finalAnswer)) {
            $lastDialog = !empty($node->dialog) ? end($node->dialog) : null;
            if (!$lastDialog || $lastDialog['text'] !== $finalAnswer) {
                $node->dialog[] = [
                    'role' => 'assistant',
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
