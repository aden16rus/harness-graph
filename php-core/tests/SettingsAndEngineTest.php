<?php
declare(strict_types=1);

namespace Harness\Tests;

require_once __DIR__ . '/../autoload.php';

use Harness\Context\ContextManager;
use Harness\Domain\Entity\Agent;
use Harness\Domain\Entity\ExecutionNode;
use Harness\Domain\Entity\Project;
use Harness\Domain\Entity\Session;
use Harness\Domain\Enum\NodeStatus;
use Harness\Domain\Enum\SessionStatus;
use Harness\Domain\Repository\AgentRepositoryInterface;
use Harness\Domain\Repository\ExecutionNodeRepositoryInterface;
use Harness\Domain\Repository\SessionRepositoryInterface;
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
use Harness\Skills\PermissionPolicy;
use Harness\Skills\SkillRegistry;
use Harness\Skills\Builtin\ReadFileSkill;
use Harness\Skills\Builtin\WriteFileSkill;

// Mock IPC client for testing ReAct engine
class MockIpcClient extends JsonRpcClient
{
    /** @var array<array> */
    public array $callLog = [];
    /** @var array<array> */
    public array $publishedEvents = [];
    /** @var array */
    public array $chatResponses = [];
    public int $chatCallCount = 0;
    public ?\Throwable $chatExceptionToThrow = null;
    public int $failChatUntilAttempt = 0;

    public function __construct()
    {
        parent::__construct('/dev/null', '127.0.0.1:0');
    }

    public function call(string $method, array $params = []): mixed
    {
        $this->callLog[] = ['method' => $method, 'params' => $params];
        return [];
    }

    public function publishEvent(string $event, string $sessionId, ?string $nodeId, array $data): bool
    {
        $this->publishedEvents[] = [
            'event' => $event,
            'session_id' => $sessionId,
            'node_id' => $nodeId,
            'data' => $data,
        ];
        return true;
    }

    public function chatLlm(array $chatRequest): array
    {
        $this->chatCallCount++;

        if ($this->failChatUntilAttempt > 0 && $this->chatCallCount <= $this->failChatUntilAttempt) {
            throw new \RuntimeException("Simulated LLM API failure on attempt {$this->chatCallCount}");
        }

        if ($this->chatExceptionToThrow !== null) {
            throw $this->chatExceptionToThrow;
        }

        if (!empty($this->chatResponses)) {
            $resp = array_shift($this->chatResponses);
            return $resp;
        }

        return [
            'content' => 'Final mock response',
            'tool_calls' => [],
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ];
    }
}

final class SettingsAndEngineTest
{
    public static function run(): void
    {
        echo "Running Settings, Subagent Step Limits, Anti-Loop, Retry and Token Limit tests...\n";

        // 1. Test SystemSettings defaults & file load
        $defaultSettings = new SystemSettings();
        assert($defaultSettings->subagentMaxSteps === 15, "Default subagent_max_steps should be 15");
        assert($defaultSettings->rootMaxSteps === 25, "Default root_max_steps should be 25");
        assert($defaultSettings->subagentMaxTokens === 50000, "Default subagent_max_tokens should be 50000");
        assert($defaultSettings->loopProtectionEnabled === true, "Default loop_protection_enabled should be true");
        assert($defaultSettings->loopDetectionThreshold === 3, "Default loop_detection_threshold should be 3");
        assert($defaultSettings->llmMaxRetries === 3, "Default llm_max_retries should be 3");
        assert($defaultSettings->llmRetryDelaySec === 3, "Default llm_retry_delay_sec should be 3");

        $tmpSettingsFile = sys_get_temp_dir() . '/test_sys_settings_' . uniqid() . '.json';
        file_put_contents($tmpSettingsFile, json_encode([
            'subagent_max_steps' => 8,
            'root_max_steps' => 40,
            'subagent_max_tokens' => 30000,
            'loop_protection_enabled' => true,
            'loop_detection_threshold' => 2,
            'llm_max_retries' => 4,
            'llm_retry_delay_sec' => 1,
        ]));
        $loadedSettings = SystemSettings::load($tmpSettingsFile);
        assert($loadedSettings->subagentMaxSteps === 8, "Loaded subagentMaxSteps should be 8");
        assert($loadedSettings->rootMaxSteps === 40, "Loaded rootMaxSteps should be 40");
        assert($loadedSettings->subagentMaxTokens === 30000, "Loaded subagentMaxTokens should be 30000");
        assert($loadedSettings->loopDetectionThreshold === 2, "Loaded loopDetectionThreshold should be 2");
        assert($loadedSettings->llmMaxRetries === 4, "Loaded llmMaxRetries should be 4");
        assert($loadedSettings->llmRetryDelaySec === 1, "Loaded llmRetryDelaySec should be 1");
        @unlink($tmpSettingsFile);
        echo "✓ SystemSettings test passed\n";

        // Set up in-memory DB and dependencies
        $pdo = Connection::createInMemory();
        (new Migrations($pdo))->up();

        $projectRepo = new SqliteProjectRepository($pdo);
        $agentRepo = new SqliteAgentRepository($pdo);
        $sessionRepo = new SqliteSessionRepository($pdo);
        $nodeRepo = new SqliteExecutionNodeRepository($pdo);
        $teamRepo = new SqliteTeamRepository($pdo);

        $skillRegistry = new SkillRegistry();
        $skillRegistry->register(new ReadFileSkill());
        $skillRegistry->register(new WriteFileSkill());

        $policy = new PermissionPolicy();
        $contextManager = new ContextManager($skillRegistry, $agentRepo);
        $project = $projectRepo->findById('proj_default');
        $session = new Session(id: 'sess_test', projectId: 'proj_default', teamId: 'team_core');
        $sessionRepo->save($session);

        $subagent = new Agent(
            id: 'agent_test_sub',
            name: 'Sub Developer',
            role: 'backend',
            systemPrompt: 'You are a test subagent',
            model: 'test-model',
            allowedSkills: ['read_file', 'write_file']
        );
        $agentRepo->save($subagent);

        $rootAgent = new Agent(
            id: 'agent_test_root',
            name: 'Root Manager',
            role: 'manager',
            systemPrompt: 'You are a test root agent',
            model: 'test-model',
            allowedSkills: ['read_file', 'write_file']
        );
        $agentRepo->save($rootAgent);

        // 2. Test Subagent Step Limit vs Root Step Limit
        $mockIpc = new MockIpcClient();
        $loopingToolResponse = [
            'content' => 'Doing step',
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'read_file', 'arguments' => '{"path": "dummy.txt"}'],
                ],
            ],
            'prompt_tokens' => 5,
            'completion_tokens' => 5,
        ];

        // 2a. Test Subagent node (depth = 1): should stop at subagentMaxSteps (5)
        $customSettings = new SystemSettings(
            subagentMaxSteps: 5,
            rootMaxSteps: 10,
            subagentMaxTokens: 50000,
            loopProtectionEnabled: false
        );

        $subAgentMgr = new SubAgentManager($agentRepo, $nodeRepo, $sessionRepo, $mockIpc, $contextManager, $skillRegistry, $policy);
        $engine = new ReActEngine(
            ipcClient: $mockIpc,
            contextManager: $contextManager,
            skillRegistry: $skillRegistry,
            permissionPolicy: $policy,
            nodeRepo: $nodeRepo,
            sessionRepo: $sessionRepo,
            subAgentManager: $subAgentMgr,
            agentRepo: $agentRepo,
            settings: $customSettings
        );

        $mockIpc->chatResponses = array_fill(0, 20, $loopingToolResponse);
        $mockIpc->chatCallCount = 0;

        $subNode = new ExecutionNode(
            id: 'node_sub_1',
            sessionId: $session->id,
            parentNodeId: 'node_root_0',
            agentId: $subagent->id,
            agentName: $subagent->name,
            role: $subagent->role,
            status: NodeStatus::ACTIVE,
            depth: 1,
            inputPrompt: 'Sub task'
        );
        $nodeRepo->save($subNode);

        $resSubNode = $engine->executeNode($subNode, $subagent, 'Sub task', $project, $session);
        assert($mockIpc->chatCallCount === 5, "Subagent should execute exactly 5 steps (subagentMaxSteps), executed: {$mockIpc->chatCallCount}");
        assert(str_contains($resSubNode->outputResult, '5'), "Subagent output should mention limit of 5: {$resSubNode->outputResult}");
        echo "✓ Subagent step limit (subagent_max_steps = 5) passed\n";

        // Check that dialog items have step numbers
        $stepsRecorded = array_filter($resSubNode->dialog, fn($d) => isset($d['step']) && $d['step'] > 0);
        assert(!empty($stepsRecorded), "Dialog should contain items with step > 0");
        echo "✓ Dialog step numbering recorded successfully\n";

        // 2b. Test Root agent node (depth = 0): should stop at rootMaxSteps (10)
        $mockIpc->chatResponses = array_fill(0, 20, $loopingToolResponse);
        $mockIpc->chatCallCount = 0;

        $rootNode = new ExecutionNode(
            id: 'node_root_1',
            sessionId: $session->id,
            parentNodeId: null,
            agentId: $rootAgent->id,
            agentName: $rootAgent->name,
            role: $rootAgent->role,
            status: NodeStatus::ACTIVE,
            depth: 0,
            inputPrompt: 'Root task'
        );
        $nodeRepo->save($rootNode);

        $resRootNode = $engine->executeNode($rootNode, $rootAgent, 'Root task', $project, $session);
        assert($mockIpc->chatCallCount === 10, "Root agent should execute exactly 10 steps (rootMaxSteps), executed: {$mockIpc->chatCallCount}");
        echo "✓ Root agent step limit (root_max_steps = 10) passed\n";

        // 3. Test Anti-Loop Protection Mechanism
        $loopSettings = new SystemSettings(
            subagentMaxSteps: 20,
            rootMaxSteps: 20,
            loopProtectionEnabled: true,
            loopDetectionThreshold: 3
        );

        $mockIpcLoop = new MockIpcClient();
        $mockIpcLoop->chatResponses = array_fill(0, 10, [
            'content' => 'Trying again',
            'tool_calls' => [
                [
                    'id' => 'call_loop',
                    'type' => 'function',
                    'function' => ['name' => 'read_file', 'arguments' => '{"path": "stuck.txt"}'],
                ],
            ],
            'prompt_tokens' => 5,
            'completion_tokens' => 5,
        ]);

        $engineLoop = new ReActEngine(
            ipcClient: $mockIpcLoop,
            contextManager: $contextManager,
            skillRegistry: $skillRegistry,
            permissionPolicy: $policy,
            nodeRepo: $nodeRepo,
            sessionRepo: $sessionRepo,
            subAgentManager: $subAgentMgr,
            agentRepo: $agentRepo,
            settings: $loopSettings
        );

        $loopNode = new ExecutionNode(
            id: 'node_loop_test',
            sessionId: $session->id,
            parentNodeId: null,
            agentId: $subagent->id,
            agentName: $subagent->name,
            role: $subagent->role,
            status: NodeStatus::ACTIVE,
            depth: 1,
            inputPrompt: 'Stuck task'
        );
        $nodeRepo->save($loopNode);

        $resLoopNode = $engineLoop->executeNode($loopNode, $subagent, 'Stuck task', $project, $session);
        assert($mockIpcLoop->chatCallCount === 3, "Anti-loop should stop at step 3, reached: {$mockIpcLoop->chatCallCount}");
        assert(str_contains($resLoopNode->outputResult, 'Защита от зацикливания') || str_contains($resLoopNode->outputResult, 'зацикливан'), "Output should indicate loop protection: {$resLoopNode->outputResult}");

        $loopEvents = array_filter($mockIpcLoop->publishedEvents, fn($e) => $e['event'] === 'graph.loop_detected');
        assert(count($loopEvents) > 0, "graph.loop_detected event should be published");
        echo "✓ Anti-loop protection (threshold = 3) passed\n";

        // 4. Test LLM API Error Retry Mechanism
        $retrySettings = new SystemSettings(
            subagentMaxSteps: 5,
            rootMaxSteps: 5,
            llmMaxRetries: 3,
            llmRetryDelaySec: 1
        );

        $mockIpcRetry = new MockIpcClient();
        $mockIpcRetry->failChatUntilAttempt = 2;
        $mockIpcRetry->chatResponses = [
            [
                'content' => 'Success after retry',
                'tool_calls' => [],
                'prompt_tokens' => 10,
                'completion_tokens' => 10,
            ],
        ];

        $engineRetry = new ReActEngine(
            ipcClient: $mockIpcRetry,
            contextManager: $contextManager,
            skillRegistry: $skillRegistry,
            permissionPolicy: $policy,
            nodeRepo: $nodeRepo,
            sessionRepo: $sessionRepo,
            subAgentManager: $subAgentMgr,
            agentRepo: $agentRepo,
            settings: $retrySettings
        );

        $retryNode = new ExecutionNode(
            id: 'node_retry_test',
            sessionId: $session->id,
            parentNodeId: null,
            agentId: $rootAgent->id,
            agentName: $rootAgent->name,
            role: $rootAgent->role,
            status: NodeStatus::ACTIVE,
            depth: 0,
            inputPrompt: 'Retry test task'
        );
        $nodeRepo->save($retryNode);

        $resRetryNode = $engineRetry->executeNode($retryNode, $rootAgent, 'Retry test task', $project, $session);
        assert($mockIpcRetry->chatCallCount === 3, "Should have made 3 attempts, made: {$mockIpcRetry->chatCallCount}");
        assert(str_contains($resRetryNode->outputResult, 'Success after retry'), "Result should be successful: {$resRetryNode->outputResult}");

        $retryEvents = array_filter($mockIpcRetry->publishedEvents, fn($e) => $e['event'] === 'graph.llm_retry');
        assert(count($retryEvents) === 2, "Expected 2 graph.llm_retry events, got: " . count($retryEvents));
        echo "✓ LLM retry mechanism (retry count = 3, retry delay = 1s) passed\n";

        // 5. Test Subagent Token Limit
        $tokenLimitSettings = new SystemSettings(
            subagentMaxSteps: 20,
            rootMaxSteps: 20,
            subagentMaxTokens: 50, // very small token limit
            loopProtectionEnabled: false
        );

        $mockIpcToken = new MockIpcClient();
        // Each step produces 25 prompt + 25 completion = 50 tokens
        $mockIpcToken->chatResponses = array_fill(0, 10, [
            'content' => 'Step with tokens',
            'tool_calls' => [
                [
                    'id' => 'call_tok',
                    'type' => 'function',
                    'function' => ['name' => 'read_file', 'arguments' => '{"path": "tok.txt"}'],
                ],
            ],
            'prompt_tokens' => 25,
            'completion_tokens' => 25,
        ]);

        $engineToken = new ReActEngine(
            ipcClient: $mockIpcToken,
            contextManager: $contextManager,
            skillRegistry: $skillRegistry,
            permissionPolicy: $policy,
            nodeRepo: $nodeRepo,
            sessionRepo: $sessionRepo,
            subAgentManager: $subAgentMgr,
            agentRepo: $agentRepo,
            settings: $tokenLimitSettings
        );

        $tokenNode = new ExecutionNode(
            id: 'node_token_test',
            sessionId: $session->id,
            parentNodeId: 'node_root_0',
            agentId: $subagent->id,
            agentName: $subagent->name,
            role: $subagent->role,
            status: NodeStatus::ACTIVE,
            depth: 1, // Subagent
            inputPrompt: 'Token heavy task'
        );
        $nodeRepo->save($tokenNode);

        $resTokenNode = $engineToken->executeNode($tokenNode, $subagent, 'Token heavy task', $project, $session);
        assert($mockIpcToken->chatCallCount === 1, "Subagent should halt after 1st step where it hits 50 tokens, ran: {$mockIpcToken->chatCallCount}");
        assert(str_contains($resTokenNode->outputResult, 'Превышен лимит токенов'), "Output should indicate token limit: {$resTokenNode->outputResult}");

        $tokEvents = array_filter($mockIpcToken->publishedEvents, fn($e) => $e['event'] === 'graph.token_limit_exceeded');
        assert(count($tokEvents) === 1, "Expected 1 graph.token_limit_exceeded event");
        echo "✓ Subagent token limit enforcement (subagent_max_tokens = 50) passed\n";

        echo "\n=======================================================\n";
        echo "ALL UNIT & INTEGRATION TESTS PASSED SUCCESSFULLY!\n";
        echo "=======================================================\n";
    }
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    SettingsAndEngineTest::run();
}
