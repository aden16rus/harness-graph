<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

$dbPath = $argv[1] ?? '/data/harness.db';
$pdo = Harness\Infrastructure\Database\Connection::get($dbPath);

$now = gmdate('Y-m-d H:i:s');

$agents = [
    [
        'id' => 'agent_manager',
        'name' => 'Project Manager',
        'role' => 'manager',
        'prompt' => 'Вы — Project Manager команды разработки. Ваша главная задача: 1. Проанализировать задачу пользователя, декомпозировать ее на этапы и составить детальный план разработки. 2. Консультироваться с Team Lead (techlead) по архитектуре и стеку через call_sub_agent. 3. Назначать задачи Backend Developer, Frontend Developer, QA Engineer через call_sub_agent. 4. Координировать саб-агентов и проверять результат на соответствие поставленным требованиям. 5. Запрашивать подтверждения у человека через ask_human_expert при необходимости. 6. Предоставить финальный отчет пользователю.',
        'model' => 'gpt-4o',
        'skills' => ['read_file', 'list_dir', 'call_sub_agent', 'ask_human_expert'],
        'sub_agents' => ['agent_techlead', 'agent_backend', 'agent_frontend', 'agent_qa'],
    ],
    [
        'id' => 'agent_techlead',
        'name' => 'Team Lead / Tech Lead',
        'role' => 'techlead',
        'prompt' => 'Вы — Team Lead / Tech Lead проекта, главный технический эксперт. Ваши обязанности: 1. Консультировать Project Manager по техническим вопросам, архитектуре и технологическому стеку приложения. 2. Детально прорабатывать контракты API, схемы данных и системные требования. 3. Проводить строгое архитектурное ревью кода разработчиков, контролировать масштабируемость и безопасность. 4. Разрешать технические споры и контролировать качество тестирования.',
        'model' => 'gpt-4o',
        'skills' => ['read_file', 'list_dir', 'host_exec', 'docker_exec', 'call_sub_agent'],
        'sub_agents' => ['agent_backend', 'agent_frontend'],
    ],
    [
        'id' => 'agent_backend',
        'name' => 'Backend Developer',
        'role' => 'backend',
        'prompt' => 'Вы — Senior Backend Developer. Ваши обязанности: 1. Писать качественный, надежный, масштабируемый и безопасный серверный код (API, сервисы, работа с БД, валидация). 2. Строго соблюдать код-стайл и архитектурные паттерны (SOLID, Clean Architecture). 3. Проводить ревью чужого бекенд-кода, аргументировать критику по код-стайлу, безопасности и производительности. 4. Исправлять замечания QA и Team Lead.',
        'model' => 'deepseek-chat',
        'skills' => ['read_file', 'write_file', 'list_dir', 'host_exec', 'docker_exec'],
        'sub_agents' => [],
    ],
    [
        'id' => 'agent_frontend',
        'name' => 'Frontend Developer',
        'role' => 'frontend',
        'prompt' => 'Вы — Senior Frontend Developer. Ваши обязанности: 1. Писать качественный, модульный и отзывчивый клиентский код (компоненты, UI/UX, стейт-менеджмент, интеграция с API). 2. Соблюдать код-стайл (TypeScript, архитектура компонентов, стиль оформления). 3. Проводить ревью чужого фронтенд-кода, высказывать критику по код-стайлу, UX и производительности рендеринга. 4. Исправлять замечания QA и Team Lead.',
        'model' => 'gpt-4o',
        'skills' => ['read_file', 'write_file', 'list_dir', 'host_exec'],
        'sub_agents' => [],
    ],
    [
        'id' => 'agent_qa',
        'name' => 'QA / Test Engineer',
        'role' => 'qa',
        'prompt' => 'Вы — QA / Test Engineer. Ваши обязанности: 1. Запускать автоматизированные тесты и линтеры в Docker-контейнере через docker_exec или локально через host_exec. 2. Вручную проверять логику и поведение приложения (проверка API ответов, граничных условий, валидации). 3. Выявлять дефекты, оформлять структурированные баг-репорты и возвращать задачи Backend/Frontend разработчикам на доработку. 4. Выдавать финальный Release Sign-off.',
        'model' => 'gpt-4o',
        'skills' => ['read_file', 'list_dir', 'docker_exec', 'host_exec', 'call_sub_agent'],
        'sub_agents' => ['agent_backend', 'agent_frontend'],
    ],
];

$insAgent = $pdo->prepare("
    INSERT INTO agents (id, name, role, system_prompt, model, temperature, token_limit, allowed_skills, allowed_sub_agent_ids, created_at)
    VALUES (:id, :name, :role, :prompt, :model, 0.2, 65536, :skills, :sub_agents, :created)
    ON CONFLICT(id) DO UPDATE SET
        name = excluded.name,
        role = excluded.role,
        system_prompt = excluded.system_prompt,
        model = excluded.model,
        allowed_skills = excluded.allowed_skills,
        allowed_sub_agent_ids = excluded.allowed_sub_agent_ids
");

$delSkill = $pdo->prepare("DELETE FROM agent_skills WHERE agent_id = :id");
$insSkill = $pdo->prepare("INSERT OR IGNORE INTO agent_skills (agent_id, skill_name, granted_at) VALUES (:aid, :sname, :created)");

foreach ($agents as $a) {
    $insAgent->execute([
        ':id' => $a['id'],
        ':name' => $a['name'],
        ':role' => $a['role'],
        ':prompt' => $a['prompt'],
        ':model' => $a['model'],
        ':skills' => json_encode($a['skills'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':sub_agents' => json_encode($a['sub_agents'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':created' => $now,
    ]);
    $delSkill->execute([':id' => $a['id']]);
    foreach ($a['skills'] as $s) {
        $insSkill->execute([':aid' => $a['id'], ':sname' => $s, ':created' => $now]);
    }
}

$updTeam = $pdo->prepare("
    INSERT INTO teams (id, project_id, name, lead_agent_id, member_agent_ids, created_at)
    VALUES (:id, 'proj_default', :name, :lead, :members, :created)
    ON CONFLICT(id) DO UPDATE SET
        name = excluded.name,
        lead_agent_id = excluded.lead_agent_id,
        member_agent_ids = excluded.member_agent_ids
");
$updTeam->execute([
    ':id' => 'team_core',
    ':name' => 'Full-Cycle Software Engineering Team',
    ':lead' => 'agent_manager',
    ':members' => json_encode(['agent_manager', 'agent_techlead', 'agent_backend', 'agent_frontend', 'agent_qa'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':created' => $now,
]);

echo "Successfully re-seeded agents with clean UTF-8 system prompts.\n";
