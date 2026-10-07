<?php
declare(strict_types=1);

namespace Harness;

use Harness\Context\ContextManager;
use Harness\Domain\Entity\ExecutionNode;
use Harness\Domain\Entity\Session;
use Harness\Domain\Enum\NodeStatus;
use Harness\Domain\Enum\SessionStatus;
use Harness\Infrastructure\Database\Connection;
use Harness\Infrastructure\Database\Migrations;
use Harness\Infrastructure\IPC\JsonRpcClient;
use Harness\Infrastructure\Repository\SqliteAgentRepository;
use Harness\Infrastructure\Repository\SqliteExecutionNodeRepository;
use Harness\Infrastructure\Repository\SqliteProjectRepository;
use Harness\Infrastructure\Repository\SqliteSessionRepository;
use Harness\Infrastructure\Repository\SqliteTeamRepository;
use Harness\Infrastructure\Settings\SystemSettings;
use Harness\Orchestrator\ReActEngine;
use Harness\Orchestrator\SubAgentManager;
use Harness\Skills\Builtin\BrowseLinkSkill;
use Harness\Skills\Builtin\AskHumanExpertSkill;
use Harness\Skills\Builtin\CallSubAgentSkill;
use Harness\Skills\Builtin\DockerExecSkill;
use Harness\Skills\Builtin\HostExecSkill;
use Harness\Skills\Builtin\ListDirectorySkill;
use Harness\Skills\Builtin\ReadFileSkill;
use Harness\Skills\Builtin\WriteFileSkill;
use Harness\Skills\PermissionPolicy;
use Harness\Skills\SkillRegistry;
use PDO;

final class HarnessApp
{
    public readonly PDO $pdo;
    public readonly SqliteProjectRepository $projectRepo;
    public readonly SqliteTeamRepository $teamRepo;
    public readonly SqliteAgentRepository $agentRepo;
    public readonly SqliteSessionRepository $sessionRepo;
    public readonly SqliteExecutionNodeRepository $nodeRepo;
    public readonly SkillRegistry $skillRegistry;
    public readonly PermissionPolicy $permissionPolicy;
    public readonly ContextManager $contextManager;
    public readonly JsonRpcClient $ipcClient;
    public readonly SubAgentManager $subAgentManager;
    public readonly ReActEngine $engine;
    public readonly SystemSettings $settings;

    public function __construct(
        string $dbPath = '/data/harness.db',
        string $ipcSocket = '/var/run/harness.sock',
        string $ipcTcp = '127.0.0.1:9099'
    ) {
        $this->pdo = Connection::get($dbPath);
        $this->projectRepo = new SqliteProjectRepository($this->pdo);
        $this->teamRepo = new SqliteTeamRepository($this->pdo);
        $this->agentRepo = new SqliteAgentRepository($this->pdo);
        $this->sessionRepo = new SqliteSessionRepository($this->pdo);
        $this->nodeRepo = new SqliteExecutionNodeRepository($this->pdo);

        $this->ipcClient = new JsonRpcClient($ipcSocket, $ipcTcp);

        $this->skillRegistry = new SkillRegistry();
        $this->registerDefaultSkills();

        $this->permissionPolicy = new PermissionPolicy();
        $this->contextManager = new ContextManager($this->skillRegistry, $this->agentRepo);

        $settingsPath = (getenv('DATA_DIR') ?: dirname($dbPath)) . '/settings.json';
        $this->settings = SystemSettings::load($settingsPath);

        $this->subAgentManager = new SubAgentManager(
            agentRepo: $this->agentRepo,
            nodeRepo: $this->nodeRepo,
            sessionRepo: $this->sessionRepo,
            ipcClient: $this->ipcClient,
            contextManager: $this->contextManager,
            skillRegistry: $this->skillRegistry,
            permissionPolicy: $this->permissionPolicy
        );

        $this->engine = new ReActEngine(
            ipcClient: $this->ipcClient,
            contextManager: $this->contextManager,
            skillRegistry: $this->skillRegistry,
            permissionPolicy: $this->permissionPolicy,
            nodeRepo: $this->nodeRepo,
            sessionRepo: $this->sessionRepo,
            subAgentManager: $this->subAgentManager,
            agentRepo: $this->agentRepo,
            settings: $this->settings
        );
    }

    private function registerDefaultSkills(): void
    {
        $this->skillRegistry->register(new ReadFileSkill());
        $this->skillRegistry->register(new WriteFileSkill());
        $this->skillRegistry->register(new ListDirectorySkill());
        $this->skillRegistry->register(new DockerExecSkill());
        $this->skillRegistry->register(new HostExecSkill());
        $this->skillRegistry->register(new AskHumanExpertSkill());
        $this->skillRegistry->register(new CallSubAgentSkill());
        $this->skillRegistry->register(new BrowseLinkSkill());
    }

    public function migrate(): void
    {
        $migrations = new Migrations($this->pdo);
        $migrations->up();
    }

    public function runSession(
        string $sessionId,
        string $task,
        string $projectId = 'proj_default',
        string $teamId = 'team_core'
    ): Session {
        $project = $this->projectRepo->findById($projectId);
        if ($project === null) {
            throw new \RuntimeException("Project '{$projectId}' not found.");
        }

        $team = $this->teamRepo->findById($teamId);
        if ($team === null) {
            throw new \RuntimeException("Team '{$teamId}' not found.");
        }

        $leadAgent = $this->agentRepo->findById($team->leadAgentId);
        if ($leadAgent === null) {
            throw new \RuntimeException("Lead agent '{$team->leadAgentId}' not found.");
        }

        $session = $this->sessionRepo->findById($sessionId);
        if ($session === null) {
            $session = new Session(
                id: $sessionId,
                projectId: $projectId,
                teamId: $teamId,
                status: SessionStatus::RUNNING
            );
        } else {
            $session->status = SessionStatus::RUNNING;
        }
        $this->sessionRepo->save($session);

        $rootNodeId = 'node_root_' . substr($sessionId, -6);
        $rootNode = new ExecutionNode(
            id: $rootNodeId,
            sessionId: $session->id,
            parentNodeId: null,
            agentId: $leadAgent->id,
            agentName: $leadAgent->name,
            role: $leadAgent->role,
            status: NodeStatus::ACTIVE,
            depth: 0,
            inputPrompt: $task,
            startedAt: gmdate('Y-m-d H:i:s')
        );
        $this->nodeRepo->save($rootNode);

        // Notify WS about root node
        $this->ipcClient->publishEvent('graph.node_created', $session->id, $rootNode->id, [
            'id' => $rootNode->id,
            'parent_node_id' => null,
            'agent_id' => $leadAgent->id,
            'agent_name' => $leadAgent->name,
            'role' => $leadAgent->role,
            'depth' => 0,
            'input_prompt' => $task,
            'status' => NodeStatus::ACTIVE->value,
            'started_at' => $rootNode->startedAt,
        ]);

        $sessionStartTime = microtime(true);

        try {
            $rootNode = $this->engine->executeNode(
                node: $rootNode,
                agent: $leadAgent,
                taskPrompt: $task,
                project: $project,
                session: $session
            );

            $rootNode->status = NodeStatus::COMPLETED;
            $rootNode->finishedAt = gmdate('Y-m-d H:i:s');
            $rootNode->durationMs = (int)((microtime(true) - $sessionStartTime) * 1000);
            $this->nodeRepo->save($rootNode);

            $this->ipcClient->publishEvent('graph.node_completed', $session->id, $rootNode->id, [
                'output_result' => $rootNode->outputResult,
                'prompt_tokens' => $rootNode->promptTokens,
                'completion_tokens' => $rootNode->completionTokens,
                'duration_ms' => $rootNode->durationMs,
                'status' => NodeStatus::COMPLETED->value,
            ]);

            $session->status = SessionStatus::COMPLETED;
            $session->finishedAt = gmdate('Y-m-d H:i:s');
            $session->totalDurationMs = (int)((microtime(true) - $sessionStartTime) * 1000);
            $this->sessionRepo->save($session);

            return $session;
        } catch (\Throwable $e) {
            $rootNode->status = NodeStatus::FAILED;
            $rootNode->outputResult = "Failed: " . $e->getMessage();
            $rootNode->finishedAt = gmdate('Y-m-d H:i:s');
            $rootNode->durationMs = (int)((microtime(true) - $sessionStartTime) * 1000);
            $this->nodeRepo->save($rootNode);

            $this->ipcClient->publishEvent('graph.node_failed', $session->id, $rootNode->id, [
                'error' => $e->getMessage(),
                'duration_ms' => $rootNode->durationMs,
                'status' => NodeStatus::FAILED->value,
            ]);

            $session->status = SessionStatus::FAILED;
            $session->finishedAt = gmdate('Y-m-d H:i:s');
            $session->totalDurationMs = (int)((microtime(true) - $sessionStartTime) * 1000);
            $this->sessionRepo->save($session);

            throw $e;
        }
    }

    /**
     * Continues an existing session and dialog with a new user message.
     * Keeps all previous thoughts, tool executions, and conversation context intact.
     */
    public function sendSessionMessage(string $sessionId, string $message, ?string $nodeId = null): Session
    {
        $session = $this->sessionRepo->findById($sessionId);
        if ($session === null) {
            throw new \RuntimeException("Session '{$sessionId}' not found.");
        }

        $project = $this->projectRepo->findById($session->projectId);
        if ($project === null) {
            throw new \RuntimeException("Project '{$session->projectId}' not found.");
        }

        $team = $this->teamRepo->findById($session->teamId);
        if ($team === null) {
            throw new \RuntimeException("Team '{$session->teamId}' not found.");
        }

        $leadAgent = $this->agentRepo->findById($team->leadAgentId);
        if ($leadAgent === null) {
            throw new \RuntimeException("Lead agent '{$team->leadAgentId}' not found.");
        }

        // Find target execution node to continue its dialog
        $targetNode = null;
        if ($nodeId !== null && trim($nodeId) !== '') {
            $targetNode = $this->nodeRepo->findById(trim($nodeId));
        }

        if ($targetNode === null) {
            $existingNodes = $this->nodeRepo->findBySessionId($sessionId);
            foreach ($existingNodes as $n) {
                if ($n->agentId === $leadAgent->id) {
                    $targetNode = $n;
                    break;
                }
            }
            if ($targetNode === null && !empty($existingNodes)) {
                $targetNode = $existingNodes[0];
            }
        }

        // If target node exists, continue its very own conversation dialog!
        if ($targetNode !== null) {
            $targetAgent = $this->agentRepo->findById($targetNode->agentId) ?? $leadAgent;

            $maxStep = 0;
            if (!empty($targetNode->dialog)) {
                foreach ($targetNode->dialog as $d) {
                    if (!empty($d['step'])) {
                        $maxStep = max($maxStep, (int)$d['step']);
                    }
                }
            }
            $continuationStep = $maxStep + 1;

            // Append the new user message to the existing dialog
            $targetNode->dialog[] = [
                'role' => 'user',
                'step' => $continuationStep,
                'text' => $message,
                'timestamp' => gmdate('Y-m-d H:i:s'),
            ];

            $targetNode->status = NodeStatus::ACTIVE;
            $session->status = SessionStatus::RUNNING;
            $this->sessionRepo->save($session);
            $this->nodeRepo->save($targetNode);

            // Notify UI about the new message and active status
            $this->ipcClient->publishEvent('graph.node_dialog_message', $session->id, $targetNode->id, [
                'role' => 'user',
                'step' => $continuationStep,
                'text' => $message,
                'timestamp' => gmdate('Y-m-d H:i:s'),
            ]);

            $startTime = microtime(true);
            try {
                $targetNode = $this->engine->executeNode(
                    node: $targetNode,
                    agent: $targetAgent,
                    taskPrompt: $message,
                    project: $project,
                    session: $session,
                    isContinuation: true
                );

                $targetNode->status = NodeStatus::COMPLETED;
                $targetNode->finishedAt = gmdate('Y-m-d H:i:s');
                $runDuration = (int)((microtime(true) - $startTime) * 1000);
                $targetNode->durationMs += $runDuration;
                $this->nodeRepo->save($targetNode);

                $this->ipcClient->publishEvent('graph.node_completed', $session->id, $targetNode->id, [
                    'output_result' => $targetNode->outputResult,
                    'prompt_tokens' => $targetNode->promptTokens,
                    'completion_tokens' => $targetNode->completionTokens,
                    'duration_ms' => $targetNode->durationMs,
                    'status' => NodeStatus::COMPLETED->value,
                ]);

                $session->status = SessionStatus::COMPLETED;
                $session->finishedAt = gmdate('Y-m-d H:i:s');
                $session->totalDurationMs += $runDuration;
                $this->sessionRepo->save($session);

                return $session;
            } catch (\Throwable $e) {
                $targetNode->status = NodeStatus::FAILED;
                $targetNode->finishedAt = gmdate('Y-m-d H:i:s');
                $runDuration = (int)((microtime(true) - $startTime) * 1000);
                $targetNode->durationMs += $runDuration;
                $this->nodeRepo->save($targetNode);

                $this->ipcClient->publishEvent('graph.node_failed', $session->id, $targetNode->id, [
                    'error' => $e->getMessage(),
                    'duration_ms' => $targetNode->durationMs,
                    'status' => NodeStatus::FAILED->value,
                ]);

                $session->status = SessionStatus::FAILED;
                $session->finishedAt = gmdate('Y-m-d H:i:s');
                $session->totalDurationMs += $runDuration;
                $this->sessionRepo->save($session);

                throw $e;
            }
        }

        // Fallback: create root node if no nodes existed
        return $this->runSession($sessionId, $message, $session->projectId, $session->teamId);
    }
}
