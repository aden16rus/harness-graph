<?php
declare(strict_types=1);

namespace Harness\Context;

use Harness\Domain\Entity\Agent;
use Harness\Domain\Entity\Project;
use Harness\Domain\Repository\AgentRepositoryInterface;
use Harness\Domain\ValueObject\Message;
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
     * project-level instructions, workspace context, and tools.
     */
    public function buildSystemPrompt(Agent $agent, Project $project): string
    {
        $prompt = "# Role: {$agent->name} ({$agent->role})
";
        $prompt .= "{$agent->systemPrompt}

";

        // 1. Global Guidelines (common for all projects across the whole system)
        $globalPrompt = trim($this->settings?->globalSystemPrompt ?? (getenv('GLOBAL_SYSTEM_PROMPT') ?: ''));
        if ($globalPrompt !== '') {
            $prompt .= "## Global Guidelines (All Projects)
";
            $prompt .= "{$globalPrompt}

";
        }

        // 2. Project Context
        $prompt .= "## Project Context
";
        $prompt .= "- Project Name: {$project->name}
";
        $prompt .= "- Tech Stack: {$project->stack}
";
        $prompt .= "- Workspace Directory: {$project->workspacePath}
";

        if ($project->defaultContainer !== null) {
            $prompt .= "- Default Docker Container: {$project->defaultContainer}
";
        }

        // 3. Project-Specific Prompt (common for all tasks inside this project)
        $projectPrompt = trim($project->projectPrompt);
        if ($projectPrompt !== '') {
            $prompt .= "
### Project Guidelines & Architecture (All Tasks in {$project->name}):
";
            $prompt .= "{$projectPrompt}

";
        }

        if ($project->guidelinesFile !== null) {
            $guidelinesPath = rtrim($project->workspacePath, "/\\") . DIRECTORY_SEPARATOR . $project->guidelinesFile;
            if (file_exists($guidelinesPath) && is_readable($guidelinesPath)) {
                $content = file_get_contents($guidelinesPath);
                if ($content !== false && trim($content) !== '') {
                    $prompt .= "
### Project Guidelines File ({$project->guidelinesFile}):
" . trim($content) . "

";
                }
            }
        }

        // Available Tools & Skills description
        $prompt .= "
## Available Skills & Tools
";
        $skills = $this->skillRegistry->getAll();
        foreach ($skills as $s) {
            if ($agent->allowsSkill($s->getName())) {
                $prompt .= "- **{$s->getName()}**: {$s->getDescription()}
";
            }
        }

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
                $prompt .= "
## Available Sub-Agents for Delegation (`call_sub_agent`)
";
                $prompt .= "You can delegate isolated sub-tasks to these specialized agents:
";
                foreach ($allowedAgents as $sub) {
                    $firstLine = strtok($sub->systemPrompt, "
") ?: '';
                    $prompt .= "- Role: `{$sub->role}` (ID: `{$sub->id}`, Name: {$sub->name}) - {$firstLine}
";
                }
                $prompt .= "CRITICAL: In `call_sub_agent`, pass the exact role or ID from the above list in `agent_role`.
";
                $prompt .= "
### ВАЖНО: ИЗОЛЯЦИЯ КОНТЕКСТА САБ-АГЕНТОВ (STATELESS)
";
                $prompt .= "- Каждый вызов `call_sub_agent` запускает НОВЫЙ изолированный подпроцесс с чистым контекстом.
";
                $prompt .= "- Саб-агент НЕ сохраняет память о предыдущих вызовах, даже если вы вызываете одну и ту же роль повторно!
";
                $prompt .= "- Вы ОБЯЗАНЫ передавать в параметрах `task` и `context` всю предысторию, пути к файлам, замечания и полные требования для выполнения подзадачи прямо сейчас.
";
                $prompt .= "- Никогда не рассчитывайте, что саб-агент «помнит» то, что вы поручали ему на прошлом шаге!
";
            }
        }

        $prompt .= "
## Workflow: Task Planning & Progress Tracking (`todo_write`)
";
        $prompt .= "1. ПЛАНИРОВАНИЕ НА 1-М ШАГЕ: В начале работы над задачей вызовите `todo_write`, чтобы зафиксировать цель (`expected_outcome`) и начальный список шагов (`todos`).
";
        $prompt .= "2. ОБНОВЛЕНИЕ СТАТУСОВ: В процессе работы вызывайте `todo_write` для отметки текущего прогресса (`in_progress` -> `completed`).
";
        $prompt .= "3. СТРОГИЕ ПРАВИЛА:
";
        $prompt .= "   - ЗАПРЕЩЕНО сбрасывать план или заново инициализировать список шагов, если работа уже идет!
";
        $prompt .= "   - ЗАПРЕЩЕНО удалять или переводить назад в pending ранее завершенные (`completed`) пункты!
";
        $prompt .= "   - Не вызывайте `todo_write` в холостую без реального изменения статусов выполнения.
";
        $prompt .= "4. ИТОГОВЫЙ ОТЧЕТ: По завершении задачи предоставьте финальный ответ с перечнем созданных файлов, статусом всех проверок и результатами.
";

        $prompt .= "
## Rules & Constraints
";
        $prompt .= "1. Operate strictly inside the workspace directory.
";
        $prompt .= "2. Reason step by step before calling tools. Do not loop endlessly over read-only checks.
";
        $prompt .= "3. If tests or tools fail, inspect the errors and self-correct.
";
        $prompt .= "4. Provide concise final answers when the task is accomplished.
";

        return trim($prompt);
    }

    /**
     * Compacts message history to keep within model token limits without losing critical context.
     * Preserves system prompt, initial task, list of created/modified files, last known todos,
     * and a generous recent window of messages with intact tool-call boundaries.
     *
     * @param array<Message> $history
     * @return array<Message>
     */
    public function fitContextWindow(array $history, int $tokenLimit = self::DEFAULT_TOKEN_LIMIT): array
    {
        $estimatedTokens = $this->estimateTokens($history);
        if ($estimatedTokens <= $tokenLimit || count($history) <= 6) {
            return $history;
        }

        $systemMsg = $history[0];
        $initialTask = $history[1] ?? null;

        $totalCount = count($history);
        // Desired tail size: keep at least 16 to 30 recent turns for full immediate context
        $desiredTail = max(10, min(30, (int)floor($totalCount * 0.45)));
        $tailStartIndex = max(2, $totalCount - $desiredTail);

        // Boundary adjustment: Ensure tail does NOT start with orphaned tool message
        while ($tailStartIndex < $totalCount && $history[$tailStartIndex]->role === 'tool') {
            if ($tailStartIndex > 2) {
                $tailStartIndex--; // step back to include the calling assistant message
            } else {
                break;
            }
        }

        $middle = array_slice($history, 2, $tailStartIndex - 2);
        $tail = array_slice($history, $tailStartIndex);

        // Extract key progress from middle messages
        $createdFiles = [];
        $lastTodosSummary = '';

        foreach ($middle as $msg) {
            if (!empty($msg->toolCalls)) {
                foreach ($msg->toolCalls as $tc) {
                    if ($tc->name === 'write_file') {
                        $p = (string)($tc->arguments['path'] ?? '');
                        if ($p !== '' && !in_array($p, $createdFiles, true)) {
                            $createdFiles[] = $p;
                        }
                    }
                }
            }
            if ($msg->role === 'tool' && str_contains($msg->content, 'TODO list updated')) {
                $lastTodosSummary = trim(substr($msg->content, 0, 300));
            }
        }

        $summaryParts = ["[Context Summary: Earlier execution executed " . count($middle) . " interaction turns.]"];
        if (!empty($createdFiles)) {
            $summaryParts[] = "Files successfully created/modified so far: " . implode(', ', array_slice($createdFiles, 0, 30)) . ". DO NOT re-create or wipe these files.";
        }
        if ($lastTodosSummary !== '') {
            $summaryParts[] = "Current Progress: " . $lastTodosSummary;
        }
        $summaryParts[] = "Continue execution of remaining tasks from the current state.";

        $summaryText = implode("
", $summaryParts);

        $compacted = [$systemMsg];
        if ($initialTask !== null) {
            $compacted[] = $initialTask;
        }
        $compacted[] = Message::user($summaryText);
        foreach ($tail as $msg) {
            $compacted[] = $msg;
        }

        return $compacted;
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
