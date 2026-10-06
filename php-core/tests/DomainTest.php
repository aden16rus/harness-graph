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
use Harness\Domain\ValueObject\Message;
use Harness\Infrastructure\Database\Connection;
use Harness\Infrastructure\Database\Migrations;
use Harness\Infrastructure\Repository\SqliteAgentRepository;
use Harness\Infrastructure\Repository\SqliteExecutionNodeRepository;
use Harness\Infrastructure\Repository\SqliteProjectRepository;
use Harness\Infrastructure\Repository\SqliteSessionRepository;
use Harness\Skills\Builtin\BrowseLinkSkill;
use Harness\Skills\Builtin\ListDirectorySkill;
use Harness\Skills\Builtin\ReadFileSkill;
use Harness\Skills\Builtin\WriteFileSkill;
use Harness\Skills\PermissionPolicy;
use Harness\Skills\SkillExecutionContext;
use Harness\Skills\SkillRegistry;
use Harness\Infrastructure\IPC\JsonRpcClient;

final class DomainTest
{
    public static function run(): void
    {
        echo "Running Domain and Skills tests...\n";
        $pdo = Connection::createInMemory();
        $migrations = new Migrations($pdo);
        $migrations->up();

        // 1. Test Project & Agent Repositories
        $projRepo = new SqliteProjectRepository($pdo);
        $projects = $projRepo->findAll();
        assert(count($projects) >= 1, "Expected at least 1 seeded project");

        $agentRepo = new SqliteAgentRepository($pdo);
        $manager = $agentRepo->findById('agent_manager');
        assert($manager !== null, "Expected agent_manager to exist");
        assert($manager->allowsSkill('call_sub_agent'), "Manager should allow call_sub_agent");
        assert(!$manager->allowsSkill('unknown_skill'), "Manager should not allow unknown_skill");

        // 2. Test Permission Policy
        $policy = new PermissionPolicy();
        assert($policy->isAllowed($manager, 'call_sub_agent'), "Policy should allow manager call_sub_agent");

        // 3. Test Skills (File read/write/list with SafePath)
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'harness_php_test_' . uniqid();
        @mkdir($tempDir, 0755, true);

        $proj = new Project(id: 'test_p', name: 'Test', workspacePath: $tempDir);
        $sess = new Session(id: 'test_s', projectId: 'test_p', teamId: 'test_t');
        $node = new ExecutionNode(
            id: 'test_n',
            sessionId: 'test_s',
            parentNodeId: null,
            agentId: 'agent_coder',
            agentName: 'Coder',
            role: 'coder',
            status: NodeStatus::ACTIVE
        );

        $ipcMock = new JsonRpcClient('/dev/null', '127.0.0.1:0');
        $ctx = new SkillExecutionContext(
            session: $sess,
            node: $node,
            agent: $manager,
            project: $proj,
            ipcClient: $ipcMock
        );

        // Write file
        $writeSkill = new WriteFileSkill();
        $resWrite = $writeSkill->execute(['path' => 'test.txt', 'content' => "Hello World\nLine 2"], $ctx);
        assert($resWrite->success, "WriteFileSkill should succeed: " . ($resWrite->error ?? ''));

        // Read file
        $readSkill = new ReadFileSkill();
        $resRead = $readSkill->execute(['path' => 'test.txt', 'offset' => 1, 'limit' => 10], $ctx);
        assert($resRead->success, "ReadFileSkill should succeed: " . ($resRead->error ?? ''));
        assert(str_contains($resRead->output, 'Hello World'), "ReadFileSkill output should contain 'Hello World'");

        // List dir
        $listSkill = new ListDirectorySkill();
        $resList = $listSkill->execute(['path' => '.'], $ctx);
        assert($resList->success, "ListDirectorySkill should succeed");
        assert(str_contains($resList->output, 'test.txt'), "ListDirectorySkill output should list test.txt");

        // Path traversal protection
        $resEscape = $writeSkill->execute(['path' => '../../escaped.txt', 'content' => 'hacked'], $ctx);
        assert(!$resEscape->success, "SafePath should block traversal outside workspace");

        // 4. Test BrowseLinkSkill definition
        $browseSkill = new BrowseLinkSkill();
        assert($browseSkill->getName() === 'browse_link', "BrowseLinkSkill name should be browse_link");
        $schema = $browseSkill->getParametersSchema();
        assert(isset($schema['properties']['url']), "BrowseLinkSkill schema should have url property");

        $resEmptyUrl = $browseSkill->execute(['url' => ''], $ctx);
        assert(!$resEmptyUrl->success, "BrowseLinkSkill should fail on empty URL");

        // Clean up
        @unlink($tempDir . DIRECTORY_SEPARATOR . 'test.txt');
        @rmdir($tempDir);

        echo "All PHP Domain & Skills tests passed successfully!\n";
    }
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    DomainTest::run();
}
