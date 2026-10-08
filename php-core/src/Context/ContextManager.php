<?php
declare(strict_types=1);

namespace Harness\Context;

use Harness\Domain\Entity\Agent;
use Harness\Domain\Entity\Project;
use Harness\Domain\Repository\AgentRepositoryInterface;
use Harness\Domain\ValueObject\Message;
use Harness\Infrastructure\Database\Connection;
use Harness\Infrastructure\Settings\SystemSettings;
use Harness\Skills\SkillRegistry;

final class ContextManager
{
    public const int DEFAULT_TOKEN_LIMIT = 65536;

    public function __construct(
        private readonly SkillRegistry $skillRegistry,
        private readonly ?AgentRepositoryInterface $agentRepo = null,
        private readonly ?SystemSettings $settings = null
    ) {}

    /**
     * Builds the complete system prompt for an agent including global guidelines,
     * project-level instructions, persistent memory, workspace context, and tools.
     */
    public function buildSystemPrompt(Agent $agent, Project $project): string
    {
        $prompt = "# Role: {$agent->name} ({$agent->role})\n";
        $prompt .= "{$agent->systemPrompt}\n\n";

        // 1. Global Guidelines (common for all projects across the whole system)
        $globalPrompt = trim($this->settings?->globalSystemPrompt ?? (getenv('GLOBAL_SYSTEM_PROMPT') ?: ''));
        if ($globalPrompt !== '') {
            $prompt .= "## Global Guidelines (All Projects)\n";
            $prompt .= "{$globalPrompt}\n\n";
        }

        // 2. Project Context
        $prompt .= "## Project Context\n";
        $prompt .= "- Project Name: {$project->name}\n";
        $prompt .= "- Tech Stack: {$project->stack}\n";
        $prompt .= "- Workspace Directory: {$project->workspacePath}\n";

        if ($project->defaultContainer !== null) {
            $prompt .= "- Default Docker Container: {$project->defaultContainer}\n";
        }

        // 3. Project-Specific Prompt (common for all tasks inside this project)
        $projectPrompt = trim($project->projectPrompt);
        if ($projectPrompt !== '') {
            $prompt .= "\n### Project Guidelines & Architecture (All Tasks in {$project->name}):\n";
            $prompt .= "{$projectPrompt}\n\n";
        }

        if ($project->guidelinesFile !== null) {
            $guidelinesPath = rtrim($project->workspacePath, "/\\") . DIRECTORY_SEPARATOR . $project->guidelinesFile;
            if (file_exists($guidelinesPath) && is_readable($guidelinesPath)) {
                $content = file_get_contents($guidelinesPath);
                if ($content !== false && trim($content) !== '') {
                    $prompt .= "\n### Project Guidelines File ({$project->guidelinesFile}):\n" . trim($content) . "\n\n";
                }
            }
        }

        // 4. Persistent Sub-Agent Memory (Saved from previous sessions for this agent in this project)
        try {
            $pdo = Connection::get();
            $stmt = $pdo->prepare('SELECT content, updated_at FROM agent_memories WHERE agent_id = :aid AND project_id = :pid');
            $stmt->execute([':aid' => $agent->id, ':pid' => $project->id]);
            $memRow = $stmt->fetch();
            $memContent = !empty($memRow['content']) ? trim((string)$memRow['content']) : '';
        } catch (\Throwable) {
            $memContent = '';
        }

        if ($memContent !== '') {
            $prompt .= "\n## 🧠 Долговременная постоянная память саб-агента (Persistent Project Memory)\n";
            $prompt .= "Ниже приведена ваша сохраненная постоянная память об этом проекте из предыдущих сессий:\n";
            $prompt .= "```markdown\n{$memContent}\n```\n";
            $prompt .= "РЕГЛАМЕНТ ИСПОЛЬЗОВАНИЯ ПАМЯТИ:\n";
            $prompt .= "1. Используйте эти данные сразу! Не выполняйте стандартных повторных действий по первичному исследованию структуры проекта, поиску команд запуска, если они уже зафиксированы в памяти.\n";
            $prompt .= "2. Если в процессе работы данные изменились или появились новые важные факты — обновите память с помощью инструмента `memory_save`.\n";
            $prompt .= "3. Держите память КОМПАКТНОЙ: не записывайте полный листинг каталогов или временный статус задач. Сохраняйте только ключевой стек, пути к важным модулям, команды запуска/тестов и специфику окружения.\n\n";
        } else {
            $prompt .= "\n## 🧠 Долговременная постоянная память саб-агента (Persistent Memory)\n";
            $prompt .= "У вас пока нет сохраненных записей о проекте.\n";
            $prompt .= "ИНСТРУКЦИЯ:\n";
            $prompt .= "- Когда в ходе работы вы определите стек проекта, ключевые входные файлы, команды запуска тестов/сборки или особенности окружения, вызовите инструмент `memory_save`, чтобы зафиксировать их в постоянной памяти.\n";
            $prompt .= "- В будущих сессиях эти данные будут доступны вам сразу, избавляя от повторных стандартных проверок.\n";
            $prompt .= "- Сохраняйте только компактные, неизменные факты (не пишите весь список файлов или временный статус).\n\n";
        }

        // Available Tools & Skills description
        $prompt .= "\n## Available Skills & Tools\n";
        $skills = $this->skillRegistry->getAll();
        foreach ($skills as $s) {
            if ($agent->allowsSkill($s->getName()) || $s->getName() === 'todo_write' || $s->getName() === 'memory_save' || ($s->getName() === 'edit_file' && $agent->allowsSkill('write_file'))) {
                $prompt .= "- **{$s->getName()}**: {$s->getDescription()}\n";
            }
        }

        // Guidelines on editing files vs writing files
        $prompt .= "\n### Правила редактирования и записи файлов:\n";
        $prompt .= "- Для точечных правок существующих файлов ВСЕГДА используйте `edit_file` (замена `old_string` -> `new_string`). Это быстро, надежно и отображает наглядный diff.\n";
        $prompt .= "- `write_file` используйте преимущественно для создания НОВЫХ файлов или полной перезаписи небольших файлов.\n";

        // If call_sub_agent is allowed, describe valid sub-agents
        if ($agent->allowsSkill('call_sub_agent') && $this->agentRepo !== null) {
            $allAgents = $this->agentRepo->findAll();
            $allowedAgents = [];
            foreach ($allAgents as $sub) {
                if ($sub->id === $agent->id) {
                    continue;
                }
                if ($agent->canCallSubAgent($sub->id, $sub->role)) {
                    $allowedAgents[] = $sub;
                }
            }
            if (!empty($allowedAgents)) {
                $prompt .= "\n## Available Sub-Agents for Delegation (`call_sub_agent`)\n";
                $prompt .= "You can delegate isolated sub-tasks to these specialized agents:\n";
                foreach ($allowedAgents as $sub) {
                    $firstLine = strtok($sub->systemPrompt, "\n") ?: '';
                    $prompt .= "- Role: `{$sub->role}` (ID: `{$sub->id}`, Name: {$sub->name}) - {$firstLine}\n";
                }
                $prompt .= "CRITICAL: In `call_sub_agent`, pass the exact role or ID from the above list in `agent_role`.\n";
                $prompt .= "\n### ВАЖНО: ИЗОЛЯЦИЯ КОНТЕКСТА САБ-АГЕНТОВ (STATELESS)\n";
                $prompt .= "- Каждый вызов `call_sub_agent` запускает изолированный подпроцесс.\n";
                $prompt .= "- Вы ОБЯЗАНЫ передавать в параметрах `task` и `context` всю предысторию, пути к файлам, замечания и полные требования для выполнения подзадачи.\n";
            }
        }

        $prompt .= "\n## Workflow: Task Planning & Progress Tracking (`todo_write`)\n";
        $prompt .= "1. ПЛАНИРОВАНИЕ НА 1-М ШАГЕ: В начале работы над задачей вызовите `todo_write`, чтобы зафиксировать цель (`expected_outcome`) и начальный список шагов (`todos`).\n";
        $prompt .= "2. ОБНОВЛЕНИЕ СТАТУСОВ: В процессе работы вызывайте `todo_write` для отметки текущего прогресса (`in_progress` -> `completed`).\n";
        $prompt .= "3. СТРОГИЕ ПРАВИЛА:\n";
        $prompt .= "   - ЗАПРЕЩЕНО сбрасывать план или заново инициализировать список шагов, если работа уже идет!\n";
        $prompt .= "   - ЗАПРЕЩЕНО удалять или переводить назад в pending ранее завершенные (`completed`) пункты!\n";
        $prompt .= "   - Не вызывайте `todo_write` в холостую без реального изменения статусов выполнения.\n";
        $prompt .= "4. ИТОГОВЫЙ ОТЧЕТ: По завершении задачи предоставьте финальный ответ с перечнем созданных файлов, статусом всех проверок и результатами.\n";

        $prompt .= "\n## Rules & Constraints\n";
        $prompt .= "1. Operate strictly inside the workspace directory.\n";
        $prompt .= "2. Reason step by step before calling tools. Do not loop endlessly over read-only checks.\n";
        $prompt .= "3. If tests or tools fail, inspect the errors and self-correct.\n";
        $prompt .= "4. Provide concise final answers when the task is accomplished.\n";

        return trim($prompt);
    }

    /**
     * Compacts message history when it approaches or exceeds the token limit.
     * Returns an array with compacted messages, whether compaction occurred, before/after token counts, and summary text.
     *
     * @param array<Message> $history
     * @return array{messages: array<Message>, compacted: bool, before_tokens: int, after_tokens: int, compacted_count: int, summary: string}
     */
    public function compactHistory(array $history, int $tokenLimit = self::DEFAULT_TOKEN_LIMIT): array
    {
        $estimatedTokens = $this->estimateTokens($history);
        if ($estimatedTokens < $tokenLimit || count($history) <= 6) {
            return [
                'messages' => $history,
                'compacted' => false,
                'before_tokens' => $estimatedTokens,
                'after_tokens' => $estimatedTokens,
                'compacted_count' => 0,
                'summary' => '',
            ];
        }

        $systemMsg = $history[0];
        $initialTask = $history[1] ?? null;

        $totalCount = count($history);
        // Keep a healthy recent window of messages (around 8 to 20 turns)
        $desiredTail = max(8, min(20, (int)floor($totalCount * 0.35)));
        $tailStartIndex = max(2, $totalCount - $desiredTail);

        // Boundary adjustment: Ensure tail does NOT start with orphaned tool message
        while ($tailStartIndex < $totalCount && $history[$tailStartIndex]->role === 'tool') {
            if ($tailStartIndex > 2) {
                $tailStartIndex--;
            } else {
                break;
            }
        }

        $middle = array_slice($history, 2, $tailStartIndex - 2);
        $tail = array_slice($history, $tailStartIndex);

        // Extract key progress from middle messages
        $createdFiles = [];
        $editedFiles = [];
        $executedCommands = [];
        $lastTodosSummary = '';
        $subagentResults = [];

        foreach ($middle as $msg) {
            if (!empty($msg->toolCalls)) {
                foreach ($msg->toolCalls as $tc) {
                    if ($tc->name === 'write_file') {
                        $p = (string)($tc->arguments['path'] ?? '');
                        if ($p !== '' && !in_array($p, $createdFiles, true)) {
                            $createdFiles[] = $p;
                        }
                    } elseif ($tc->name === 'edit_file') {
                        $p = (string)($tc->arguments['path'] ?? '');
                        if ($p !== '' && !in_array($p, $editedFiles, true)) {
                            $editedFiles[] = $p;
                        }
                    } elseif ($tc->name === 'docker_exec' || $tc->name === 'host_exec') {
                        $rawCmd = $tc->arguments['cmd'] ?? ($tc->arguments['command'] ?? '');
                        $cmdStr = is_array($rawCmd) ? implode(' ', $rawCmd) : (string)$rawCmd;
                        if ($cmdStr !== '' && count($executedCommands) < 8) {
                            $executedCommands[] = substr($cmdStr, 0, 80);
                        }
                    } elseif ($tc->name === 'call_sub_agent') {
                        $role = (string)($tc->arguments['agent_role'] ?? '');
                        $task = (string)($tc->arguments['task'] ?? '');
                        if ($role !== '' && count($subagentResults) < 6) {
                            $subagentResults[] = "Делегировано {$role}: " . substr($task, 0, 80);
                        }
                    }
                }
            }
            if ($msg->role === 'tool' && str_contains($msg->content, 'TODO list updated')) {
                $lastTodosSummary = trim(substr($msg->content, 0, 300));
            }
        }

        $summaryParts = ["[Краткая суммаризация сжатой предыстории (" . count($middle) . " сообщений):]"];
        if (!empty($createdFiles)) {
            $summaryParts[] = "• Созданные файлы: " . implode(', ', array_slice($createdFiles, 0, 20));
        }
        if (!empty($editedFiles)) {
            $summaryParts[] = "• Отредактированные файлы: " . implode(', ', array_slice($editedFiles, 0, 20));
        }
        if (!empty($executedCommands)) {
            $summaryParts[] = "• Выполненные команды: " . implode('; ', array_slice($executedCommands, 0, 6));
        }
        if (!empty($subagentResults)) {
            $summaryParts[] = "• Вызовы саб-агентов: " . implode(' | ', array_slice($subagentResults, 0, 4));
        }
        if ($lastTodosSummary !== '') {
            $summaryParts[] = "• Статус TODO: " . $lastTodosSummary;
        }
        $summaryParts[] = "• Текущее состояние: Ранние шаги завершены, продолжайте выполнение с актуальными файлами и текущими результатами.";

        $summaryText = implode("\n", $summaryParts);

        $compactedMessages = [$systemMsg];
        if ($initialTask !== null) {
            $compactedMessages[] = $initialTask;
        }
        $compactedMessages[] = Message::user("📦 [Сжатие контекста диалога]:\n" . $summaryText);
        foreach ($tail as $msg) {
            $compactedMessages[] = $msg;
        }

        $afterTokens = $this->estimateTokens($compactedMessages);

        return [
            'messages' => $compactedMessages,
            'compacted' => true,
            'before_tokens' => $estimatedTokens,
            'after_tokens' => $afterTokens,
            'compacted_count' => count($middle),
            'summary' => $summaryText,
        ];
    }

    /**
     * Backward-compatible helper method.
     *
     * @param array<Message> $history
     * @return array<Message>
     */
    public function fitContextWindow(array $history, int $tokenLimit = self::DEFAULT_TOKEN_LIMIT): array
    {
        return $this->compactHistory($history, $tokenLimit)['messages'];
    }

    /**
     * @param array<Message> $messages
     */
    public function estimateTokens(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $msg) {
            $chars += strlen($msg->content);
            if (!empty($msg->toolCalls)) {
                foreach ($msg->toolCalls as $tc) {
                    $chars += strlen($tc->name) + strlen(json_encode($tc->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
        }

        return (int)ceil($chars / 3.5);
    }
}