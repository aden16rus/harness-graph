<?php
declare(strict_types=1);

namespace Harness\Context;

use Harness\Domain\Entity\Agent;
use Harness\Domain\Entity\Project;
use Harness\Domain\Repository\AgentRepositoryInterface;
use Harness\Domain\ValueObject\Message;
use Harness\Skills\SkillRegistry;

final class ContextManager
{
    public function __construct(
        private readonly SkillRegistry $skillRegistry,
        private readonly ?AgentRepositoryInterface $agentRepo = null
    ) {}

    /**
     * Builds the complete system prompt for an agent including project context and tools.
     */
    public function buildSystemPrompt(Agent $agent, Project $project): string
    {
        $prompt = "# Role: {$agent->name} ({$agent->role})\n";
        $prompt .= "{$agent->systemPrompt}\n\n";

        $prompt .= "## Project Context\n";
        $prompt .= "- Project Name: {$project->name}\n";
        $prompt .= "- Tech Stack: {$project->stack}\n";
        $prompt .= "- Workspace Directory: {$project->workspacePath}\n";

        if ($project->defaultContainer !== null) {
            $prompt .= "- Default Docker Container: {$project->defaultContainer}\n";
        }

        if ($project->guidelinesFile !== null) {
            $guidelinesPath = rtrim($project->workspacePath, '/\\') . DIRECTORY_SEPARATOR . $project->guidelinesFile;
            if (file_exists($guidelinesPath) && is_readable($guidelinesPath)) {
                $content = file_get_contents($guidelinesPath);
                if ($content !== false && trim($content) !== '') {
                    $prompt .= "\n### Project Guidelines ({$project->guidelinesFile}):\n" . trim($content) . "\n\n";
                }
            }
        }

        // Available Tools & Skills description
        $prompt .= "\n## Available Skills & Tools\n";
        $skills = $this->skillRegistry->getAll();
        foreach ($skills as $s) {
            if ($agent->allowsSkill($s->getName())) {
                $prompt .= "- **{$s->getName()}**: {$s->getDescription()}\n";
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
                $prompt .= "\n## Available Sub-Agents for Delegation (`call_sub_agent`)\n";
                $prompt .= "You can delegate isolated sub-tasks to these specialized agents:\n";
                foreach ($allowedAgents as $sub) {
                    $firstLine = strtok($sub->systemPrompt, "\n") ?: '';
                    $prompt .= "- Role: `{$sub->role}` (ID: `{$sub->id}`, Name: {$sub->name}) - {$firstLine}\n";
                }
                $prompt .= "CRITICAL: In `call_sub_agent`, pass the exact role or ID from the above list in `agent_role`.\n";
            }
        }

        $prompt .= "\n## Rules & Constraints\n";
        $prompt .= "1. Operate strictly inside the workspace directory.\n";
        $prompt .= "2. Reason step by step before calling tools.\n";
        $prompt .= "3. If tests or tools fail, inspect the errors and self-correct.\n";
        $prompt .= "4. Provide concise final answers when the task is accomplished.\n";

        return trim($prompt);
    }

    /**
     * Compacts or prunes message history to keep within model token limits.
     * Keeps system prompt and initial task, summarizes or drops middle tool interactions if limit exceeded.
     *
     * @param array<Message> $history
     * @return array<Message>
     */
    public function fitContextWindow(array $history, int $tokenLimit = 8192): array
    {
        $estimatedTokens = $this->estimateTokens($history);
        if ($estimatedTokens <= $tokenLimit || count($history) <= 4) {
            return $history;
        }

        // We need compaction:
        // Keep index 0 (System) and index 1 (Initial User Task)
        // Keep the last N messages (recent context)
        // Compress middle messages
        $systemMsg = $history[0];
        $initialTask = $history[1] ?? null;

        $tailCount = max(2, min(6, count($history) - 2));
        $tail = array_slice($history, -$tailCount);

        $middle = array_slice($history, 2, count($history) - 2 - $tailCount);
        $summaryText = "[Context Summary: Earlier steps performed " . count($middle) . " tool interactions and operations.]";

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
                    $chars += strlen($tc->name) + strlen(json_encode($tc->arguments));
                }
            }
        }

        // Standard rough estimate: ~4 characters per token
        return (int)ceil($chars / 3.5);
    }
}
