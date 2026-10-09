<?php
declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Harness\Infrastructure\Database\Connection;

$pdo = Connection::get();
$now = gmdate('Y-m-d H:i:s');

$jsonPath = __DIR__ . '/../../agents_dump.json';
if (!file_exists($jsonPath)) {
    echo "agents_dump.json not found, skipping seeding.\n";
    exit(0);
}

$agents = json_decode(file_get_contents($jsonPath), true) ?: [];

// Uses strictly INSERT OR IGNORE so existing user configurations in UI are NEVER overwritten!
$insAgent = $pdo->prepare("
    INSERT OR IGNORE INTO agents (id, name, role, system_prompt, model, temperature, token_limit, allowed_skills, allowed_sub_agent_ids, created_at)
    VALUES (:id, :name, :role, :prompt, :model, :temp, 65536, :skills, :sub_agents, :created)
");

$insSkill = $pdo->prepare("
    INSERT OR IGNORE INTO agent_skills (agent_id, skill_name, granted_at)
    VALUES (:aid, :sname, :created)
");

foreach ($agents as $a) {
    $insAgent->execute([
        ':id' => $a['id'],
        ':name' => $a['name'],
        ':role' => $a['role'],
        ':prompt' => $a['prompt'],
        ':model' => $a['model'] ?? 'gpt-4o',
        ':temp' => $a['temperature'] ?? 0.2,
        ':skills' => json_encode($a['skills'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':sub_agents' => json_encode($a['sub_agents'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':created' => $now,
    ]);
    foreach ($a['skills'] as $s) {
        $insSkill->execute([':aid' => $a['id'], ':sname' => $s, ':created' => $now]);
    }
}

$allAgentIds = array_column($agents, 'id');
$insTeam = $pdo->prepare("
    INSERT OR IGNORE INTO teams (id, project_id, name, lead_agent_id, member_agent_ids, created_at)
    VALUES ('team_core', 'proj_default', 'Full-Cycle Software Engineering Team', 'agent_manager', :members, :created)
");
$insTeam->execute([
    ':members' => json_encode($allAgentIds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':created' => $now,
]);

echo "seed_agents.php safely executed (INSERT OR IGNORE: existing user data preserved).\n";