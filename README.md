# Harness Agent

Модульный автономный harness-агент с мультиагентной иерархией, выполнением команд в Docker-контейнерах целевого проекта и интерактивной визуализацией графа вызовов в реальном времени.

## Архитектура системы

- **Frontend (`frontend/`)**: Svelte 5 SPA, `@xyflow/svelte` граф вызовов в реальном времени, Inspector Panel со стримингом рассуждений, логами вызовов инструментов и Human-in-the-Loop формой подтверждений.
- **Go Engine (`go-engine/`)**: Высокопроизводительный демон, JSON-RPC 2.0 сервер поверх Unix Domain Socket (`/var/run/harness.sock`) и TCP (`127.0.0.1:9099`), WebSocket Hub, Docker Socket API SDK клиент, Local Runner, OpenAI-совместимый LLM Streaming Proxy (SSE, TTFT, замер токенов) и асинхронный NDJSON логгер (`/data/logs/{session_id}/{node_id}.ndjson`).
- **PHP 8.3 Core (`php-core/`)**: Чистая доменная модель (Clean Architecture / PSR-4), ReAct Loop с самокоррекцией, Sub-Agent Manager с контекстной изоляцией и защитой от бесконечной рекурсии, Skill Registry со строгими схемами параметров и Permission Policy, SQLite хранилище (`/data/harness.db`).

---

## Структура каталогов

```
.
├── docker/
│   ├── Dockerfile                  # Multi-stage Dockerfile (Go + Svelte 5 + PHP 8.3)
│   └── entrypoint.sh               # Скрипт инициализации и запуска сервисов
├── docker-compose.yml              # Оркестрация с монтированием docker.sock и /workspace
├── go-engine/
│   ├── cmd/server/main.go          # Входная точка демона Go
│   ├── internal/
│   │   ├── config/                 # Конфигурация приложения
│   │   ├── docker/                 # Клиент Docker Socket SDK (ExecInContainer)
│   │   ├── runner/                 # Локальный Runner с контролем таймаутов
│   │   ├── llm/                    # OpenAI SSE прокси и расчет TTFT/токенов
│   │   ├── ws/                     # WebSocket Hub и маршрутизация ответов оператора
│   │   ├── logger/                 # Неблокирующий NDJSON логгер трейсов
│   │   ├── ipc/                    # JSON-RPC 2.0 сервер (Unix Socket & TCP)
│   │   └── e2e/                    # E2E и интеграционные тесты
├── php-core/
│   ├── bin/harness                 # CLI утилита (migrate, run, status)
│   ├── src/
│   │   ├── Domain/                 # Entity, ValueObject, Enum, Repository interfaces
│   │   ├── Infrastructure/         # SQLite репозитории, миграции, JSON-RPC IPC клиент
│   │   ├── Context/                # ContextManager с авто-сжатием контекста
│   │   ├── Skills/                 # SkillInterface, Registry, PermissionPolicy
│   │   │   └── Builtin/            # ReadFile, WriteFile, ListDir, DockerExec, HostExec, AskHuman, CallSubAgent
│   │   ├── Orchestrator/           # ReActEngine, SubAgentManager
│   │   └── HarnessApp.php          # Сборка приложения
│   └── tests/                      # Юнит-тесты доменного ядра и скилов
└── frontend/
    ├── src/
    │   ├── lib/
    │   │   ├── components/         # FlowGraph, CustomNode, InspectorPanel, MetricsHeader, SettingsModal
    │   │   ├── stores/             # WebSocket менеджер и реактивные хранилища состояния
    │   │   └── types/              # Типы TypeScript
    │   ├── App.svelte              # Главный компонент интерфейса
    │   └── main.ts                 # Инициализация Svelte 5
    └── vite.config.js              # Конфигурация Vite и TailwindCSS v4
```

---

## Запуск в Docker (Production / Full Setup)

1. Укажите ваш API ключ и путь к целевому проекту в `.env` (или передайте в команду):
```bash
export OPENAI_API_KEY="sk-..."
export WORKSPACE_PATH="/path/to/your/project"
```

2. Запустите контейнер:
```bash
docker compose up --build -d
```

3. Откройте веб-интерфейс в браузере:
```
http://localhost:8080
```

---

## Локальный запуск компонентов для разработки

### 1. Go Engine
```bash
cd go-engine
go build -o bin/harness-engine ./cmd/server
./bin/harness-engine --http-port=8080 --workspace-dir=../workspace --data-dir=../data
```

### 2. Frontend (Svelte 5)
```bash
cd frontend
npm install
npm run dev
```

### 3. PHP Core (CLI)
```bash
cd php-core
# Миграция базы данных
php bin/harness migrate --db=../data/harness.db

# Запуск задачи мультиагентного выполнения
php bin/harness run --task="Создать класс калькулятора скидок и покрыть его юнит-тестами"
```

### Запуск тестов
```bash
# E2E и интеграционные тесты Go демона, шины WS и логгера
cd go-engine
go test -v ./...

# Тесты PHP Core домена и скилов
cd php-core
php tests/DomainTest.php
```
