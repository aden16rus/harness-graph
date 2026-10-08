<?php
declare(strict_types=1);

namespace Harness\Infrastructure\Database;

use PDO;

final class Migrations
{
    public function __construct(private readonly PDO $pdo) {}

    public function up(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS projects (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                workspace_path TEXT NOT NULL,
                stack TEXT NOT NULL DEFAULT 'general',
                default_container TEXT,
                guidelines_file TEXT,
                default_team_id TEXT DEFAULT 'team_core',
                project_prompt TEXT DEFAULT '',
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS agents (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                role TEXT NOT NULL,
                system_prompt TEXT NOT NULL,
                model TEXT NOT NULL DEFAULT 'gpt-4o',
                temperature REAL NOT NULL DEFAULT 0.2,
                token_limit INTEGER NOT NULL DEFAULT 8192,
                allowed_skills TEXT NOT NULL,
                llm_profile_id TEXT,
                allowed_sub_agent_ids TEXT,
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS teams (
                id TEXT PRIMARY KEY,
                project_id TEXT NOT NULL,
                name TEXT NOT NULL,
                lead_agent_id TEXT NOT NULL,
                member_agent_ids TEXT NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (lead_agent_id) REFERENCES agents(id)
            );

            CREATE TABLE IF NOT EXISTS agent_skills (
                agent_id TEXT NOT NULL,
                skill_name TEXT NOT NULL,
                granted_at TEXT NOT NULL,
                PRIMARY KEY (agent_id, skill_name),
                FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                project_id TEXT NOT NULL,
                team_id TEXT NOT NULL,
                status TEXT NOT NULL,
                total_prompt_tokens INTEGER NOT NULL DEFAULT 0,
                total_completion_tokens INTEGER NOT NULL DEFAULT 0,
                total_duration_ms INTEGER NOT NULL DEFAULT 0,
                started_at TEXT NOT NULL,
                finished_at TEXT,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS execution_nodes (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                parent_node_id TEXT,
                agent_id TEXT NOT NULL,
                agent_name TEXT NOT NULL,
                role TEXT NOT NULL,
                status TEXT NOT NULL,
                depth INTEGER NOT NULL DEFAULT 0,
                input_prompt TEXT NOT NULL,
                output_result TEXT NOT NULL,
                prompt_tokens INTEGER NOT NULL DEFAULT 0,
                completion_tokens INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                active_tool TEXT,
                started_at TEXT NOT NULL,
                finished_at TEXT,
                dialog TEXT,
                tool_calls TEXT,
                todos TEXT DEFAULT '[]',
                expected_outcome TEXT DEFAULT '',
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_nodes_session ON execution_nodes(session_id);
            CREATE INDEX IF NOT EXISTS idx_nodes_parent ON execution_nodes(parent_node_id);

            
            CREATE TABLE IF NOT EXISTS agent_memories (
                id TEXT PRIMARY KEY,
                agent_id TEXT NOT NULL,
                project_id TEXT NOT NULL,
                content TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(agent_id, project_id),
                FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_memories_agent_proj ON agent_memories(agent_id, project_id);

            CREATE TABLE IF NOT EXISTS scheduled_tasks (
                id TEXT PRIMARY KEY,
                task TEXT NOT NULL,
                project_id TEXT NOT NULL DEFAULT 'proj_default',
                team_id TEXT NOT NULL DEFAULT 'team_core',
                run_at TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                session_id TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_sched_status_run ON scheduled_tasks(status, run_at);
        ");

        try {
            $this->pdo->exec("ALTER TABLE projects ADD COLUMN default_team_id TEXT DEFAULT 'team_core';");
        } catch (\Throwable) {
            // Already exists
        }

        try {
            $this->pdo->exec("ALTER TABLE agents ADD COLUMN llm_profile_id TEXT;");
        } catch (\Throwable) {
            // Already exists
        }

        try {
            $this->pdo->exec("ALTER TABLE agents ADD COLUMN allowed_sub_agent_ids TEXT;");
        } catch (\Throwable) {
            // Already exists
        }

        try {
            $this->pdo->exec("ALTER TABLE execution_nodes ADD COLUMN dialog TEXT;");
        } catch (\Throwable) {
            // Already exists
        }

        try {
            $this->pdo->exec("ALTER TABLE execution_nodes ADD COLUMN tool_calls TEXT;");
        } catch (\Throwable) {
            // Already exists
        }

        try {
            $this->pdo->exec("ALTER TABLE projects ADD COLUMN project_prompt TEXT DEFAULT '';");
        } catch (\Throwable) {
            // Already exists
        }

        try {
            $this->pdo->exec("ALTER TABLE execution_nodes ADD COLUMN todos TEXT DEFAULT '[]';");
        } catch (\Throwable) {
            // Already exists
        }

        try {
            $this->pdo->exec("ALTER TABLE execution_nodes ADD COLUMN expected_outcome TEXT DEFAULT '';");
        } catch (\Throwable) {
            // Already exists
        }

        $this->seedDefaults();
    }

    private function seedDefaults(): void
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM projects");
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        // Default Project
        $this->pdo->prepare("
            INSERT INTO projects (id, name, workspace_path, stack, default_container, guidelines_file, created_at)
            VALUES (:id, :name, :ws, :stack, :container, :guidelines, :created)
        ")->execute([
            ':id' => 'proj_default',
            ':name' => 'Default Target Project',
            ':ws' => '/workspace',
            ':stack' => 'php-fullstack',
            ':container' => 'app-container',
            ':guidelines' => 'README.md',
            ':created' => $now,
        ]);

        // Default Agents (Manager, TechLead, Backend, Frontend, QA)
        $agents = [
            [
                'id' => 'agent_manager',
                'name' => 'Project Manager',
                'role' => 'manager',
                'prompt' => 'Вы — Project Manager команды разработки. Ваша главная задача: 1. Проанализировать задачу пользователя, декомпозировать ее на этапы и составить детальный план разработки. 2. Консультироваться с Team Lead (techlead) по архитектуре и стеку через call_sub_agent. 3. Назначать задачи Backend Developer, Frontend Developer, QA Engineer через call_sub_agent. ВАЖНО: каждый вызов саб-агента через call_sub_agent полностью изолирован и НЕ сохраняет контекст предыдущих вызовов! Всегда передавайте в task полную предысторию, пути к файлам и точные требования. 4. Координировать саб-агентов и проверять результат на соответствие поставленным требованиям. 5. Запрашивать подтверждения у человека через ask_human_expert при необходимости. 6. Предоставить финальный отчет пользователю.',
                'model' => 'gpt-4o',
                'skills' => json_encode(['read_file', 'list_dir', 'call_sub_agent', 'ask_human_expert']),
            ],
            [
                'id' => 'agent_techlead',
                'name' => 'Team Lead / Tech Lead',
                'role' => 'techlead',
                'prompt' => 'Вы — Team Lead / Tech Lead проекта, главный технический эксперт. Ваши обязанности: 1. Консультировать Project Manager по техническим вопросам, архитектуре и технологическому стеку приложения. 2. Детально прорабатывать контракты API, схемы данных и системные требования. 3. Проводить строгое архитектурное ревью кода разработчиков, контролировать масштабируемость и безопасность. 4. Разрешать технические споры и контролировать качество тестирования.',
                'model' => 'gpt-4o',
                'skills' => json_encode(['read_file', 'list_dir', 'host_exec', 'docker_exec', 'call_sub_agent']),
            ],
            [
                'id' => 'agent_backend',
                'name' => 'Backend Developer',
                'role' => 'backend',
                'prompt' => 'Вы — Senior Backend Developer. Ваши обязанности: 1. Писать качественный, надежный, масштабируемый и безопасный серверный код (API, сервисы, работа с БД, валидация). 2. Строго соблюдать код-стайл и архитектурные паттерны (SOLID, Clean Architecture). 3. Проводить ревью чужого бекенд-кода, аргументировать критику по код-стайлу, безопасности и производительности. 4. Исправлять замечания QA и Team Lead.',
                'model' => 'gpt-4o',
                'skills' => json_encode(['read_file', 'write_file', 'list_dir', 'host_exec', 'docker_exec']),
            ],
            [
                'id' => 'agent_frontend',
                'name' => 'Frontend Developer',
                'role' => 'frontend',
                'prompt' => 'Вы — Senior Frontend Developer. Ваши обязанности: 1. Писать качественный, модульный и отзывчивый клиентский код (компоненты, UI/UX, стейт-менеджмент, интеграция с API). 2. Соблюдать код-стайл (TypeScript, архитектура компонентов, стиль оформления). 3. Проводить ревью чужого фронтенд-кода, высказывать критику по код-стайлу, UX и производительности рендеринга. 4. Исправлять замечания QA и Team Lead.',
                'model' => 'gpt-4o',
                'skills' => json_encode(['read_file', 'write_file', 'list_dir', 'host_exec']),
            ],
            [
                'id' => 'agent_qa',
                'name' => 'QA / Test Engineer',
                'role' => 'qa',
                'prompt' => 'Вы — QA / Test Engineer. Ваши обязанности: 1. Запускать автоматизированные тесты и линтеры в Docker-контейнере через docker_exec или локально через host_exec. 2. Вручную проверять логику и поведение приложения (проверка API ответов, граничных условий, валидации). 3. Выявлять дефекты, оформлять структурированные баг-репорты и возвращать задачи Backend/Frontend разработчикам на доработку. 4. Выдавать финальный Release Sign-off.',
                'model' => 'gpt-4o',
                'skills' => json_encode(['read_file', 'list_dir', 'docker_exec', 'host_exec', 'call_sub_agent']),
            ],
        ];

        $insAgent = $this->pdo->prepare("
            INSERT INTO agents (id, name, role, system_prompt, model, temperature, token_limit, allowed_skills, created_at)
            VALUES (:id, :name, :role, :prompt, :model, 0.2, 8192, :skills, :created)
        ");

        $insSkill = $this->pdo->prepare("
            INSERT OR IGNORE INTO agent_skills (agent_id, skill_name, granted_at)
            VALUES (:aid, :sname, :created)
        ");

        foreach ($agents as $a) {
            $insAgent->execute([
                ':id' => $a['id'],
                ':name' => $a['name'],
                ':role' => $a['role'],
                ':prompt' => $a['prompt'],
                ':model' => $a['model'],
                ':skills' => $a['skills'],
                ':created' => $now,
            ]);

            $skillList = json_decode($a['skills'], true);
            foreach ($skillList as $s) {
                $insSkill->execute([':aid' => $a['id'], ':sname' => $s, ':created' => $now]);
            }
        }

        // Default Team with Manager as Lead
        $this->pdo->prepare("
            INSERT INTO teams (id, project_id, name, lead_agent_id, member_agent_ids, created_at)
            VALUES (:id, :pid, :name, :lead, :members, :created)
        ")->execute([
            ':id' => 'team_core',
            ':pid' => 'proj_default',
            ':name' => 'Full-Cycle Software Engineering Team',
            ':lead' => 'agent_manager',
            ':members' => json_encode(['agent_manager', 'agent_techlead', 'agent_backend', 'agent_frontend', 'agent_qa']),
            ':created' => $now,
        ]);
    }
}
