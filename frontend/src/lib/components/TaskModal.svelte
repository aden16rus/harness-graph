<script lang="ts">
  import { sessionStore, wsManager, clearGraph } from '../stores/websocket';
  import { activeProjectId, projectsList, setActiveProject } from '../stores/projectStore';

  export let isOpen = false;
  export let onClose: () => void = () => {};

  let taskText = 'Создать класс калькулятора скидок и покрыть его юнит-тестами';
  let isSubmitting = false;

  let projects: any[] = [];
  let teams: any[] = [];
  let agents: any[] = [];

  let selectedProjectId = 'proj_default';
  let selectedTeamId = 'team_core';

  // Scheduling state
  let isScheduled = false;
  let delayMinutes = 5;
  let customRunAt = '';

  $: if (isOpen) {
    selectedProjectId = $activeProjectId || 'proj_default';
    loadData();
  }

  async function loadData() {
    try {
      const [projRes, teamRes, agentRes] = await Promise.all([
        fetch('/api/projects'),
        fetch('/api/teams'),
        fetch('/api/agents'),
      ]);
      if (projRes.ok) {
        projects = await projRes.json();
        if (projects.length > 0) {
          if (!projects.find(p => p.id === selectedProjectId)) {
            selectedProjectId = projects[0].id;
          }
        }
      }
      if (teamRes.ok) {
        teams = await teamRes.json();
      }
      if (agentRes.ok) {
        agents = await agentRes.json();
      }
      syncTeamForProject();
    } catch (e) {
      console.warn('Failed to load projects/teams:', e);
    }
  }

  function onProjectChange() {
    setActiveProject(selectedProjectId);
    syncTeamForProject();
  }

  function syncTeamForProject() {
    const proj = projects.find(p => p.id === selectedProjectId);
    if (proj?.default_team_id && teams.find(t => t.id === proj.default_team_id)) {
      selectedTeamId = proj.default_team_id;
    } else if (teams.length > 0) {
      selectedTeamId = teams[0].id;
    }
  }

  $: selectedProject = projects.find(p => p.id === selectedProjectId);
  $: currentTeam = teams.find(t => t.id === selectedTeamId) || (teams.length > 0 ? teams[0] : null);
  $: leadAgent = agents.find(a => a.id === currentTeam?.lead_agent_id);
  $: memberAgents = agents.filter(a => currentTeam?.member_agent_ids?.includes(a.id));

  async function handleStart() {
    if (!taskText.trim()) return;
    isSubmitting = true;

    try {
      if (isScheduled) {
        let runAt = '';
        if (customRunAt) {
          runAt = new Date(customRunAt).toISOString().replace('T', ' ').substring(0, 19);
        } else {
          runAt = new Date(Date.now() + delayMinutes * 60000).toISOString().replace('T', ' ').substring(0, 19);
        }

        await fetch('/api/tasks/scheduled', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            task: taskText.trim(),
            project_id: selectedProjectId,
            team_id: selectedTeamId,
            run_at: runAt,
          }),
        });
      } else {
        const sessionId = 'sess_' + Math.random().toString(36).substring(2, 9);
        clearGraph();
        sessionStore.update(s => ({
          ...s,
          sessionId,
          status: 'running',
        }));
        wsManager.connect(sessionId);

        await fetch('/api/session/start', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            session_id: sessionId,
            task: taskText.trim(),
            project_id: selectedProjectId,
            team_id: selectedTeamId,
          }),
        });
      }
    } catch (e) {
      console.error(e);
    } finally {
      isSubmitting = false;
      onClose();
    }
  }
</script>

{#if isOpen}
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm select-none">
    <div class="w-full max-w-lg rounded-2xl bg-slate-900 border border-slate-800 shadow-2xl overflow-hidden text-slate-100">
      <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
        <h2 class="font-bold text-sm text-slate-100 flex items-center gap-2">
          <span>🚀</span> Запуск Multi-Agent Сессии
        </h2>
        <button on:click={onClose} class="text-slate-500 hover:text-slate-300 cursor-pointer">✕</button>
      </div>

      <div class="p-6 space-y-4 text-xs font-sans">
        <!-- Project & Team Selector -->
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label for="modal-project-select" class="block text-slate-400 font-medium mb-1">Target Project (Проект):</label>
            <select
              id="modal-project-select"
              bind:value={selectedProjectId}
              on:change={onProjectChange}
              class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2 text-slate-100 focus:outline-none focus:border-indigo-500 font-medium cursor-pointer"
            >
              {#each projects as p}
                <option value={p.id}>{p.name}</option>
              {/each}
            </select>
            {#if selectedProject?.workspace_path}
              <span class="text-[10px] text-slate-400 font-mono block mt-1 truncate" title={selectedProject.workspace_path}>
                📁 {selectedProject.workspace_path}
              </span>
            {/if}
          </div>

          <div>
            <label for="modal-team-select" class="block text-slate-400 font-medium mb-1">Sub-Agent Team (Команда):</label>
            <select
              id="modal-team-select"
              bind:value={selectedTeamId}
              class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2 text-slate-100 focus:outline-none focus:border-indigo-500 font-medium cursor-pointer"
            >
              {#each teams as tm}
                <option value={tm.id}>{tm.name}</option>
              {/each}
            </select>
            <span class="text-[10px] text-slate-400 block mt-1">
              👥 {memberAgents.length} саб-агентов
            </span>
          </div>
        </div>

        <!-- Team Preview Card -->
        {#if currentTeam}
          <div class="p-3 rounded-xl border border-slate-800 bg-slate-950/60 text-[11px] space-y-2">
            <div class="flex items-center justify-between bg-amber-950/30 border border-amber-900/40 p-2 rounded-lg">
              <span class="text-slate-300 font-medium flex items-center gap-1.5">
                <span class="text-amber-400">👑</span> Главный саб-агент (Получатель задачи):
              </span>
              <span class="text-amber-300 font-bold">{leadAgent?.name || 'Lead'} <span class="text-[10px] text-amber-400/70 font-normal">({leadAgent?.role || 'lead'})</span></span>
            </div>
            <div>
              <span class="text-slate-400 font-medium block mb-1">Состав команды ({memberAgents.length}):</span>
              <div class="flex flex-wrap gap-1.5">
                {#each memberAgents as m}
                  <span class="px-2 py-0.5 rounded bg-slate-900 border border-slate-800 text-slate-300 text-[10px] flex items-center gap-1">
                    {#if m.id === currentTeam.lead_agent_id}
                      <span class="text-amber-400">👑</span>
                    {/if}
                    {m.name}
                  </span>
                {/each}
              </div>
            </div>
          </div>
        {/if}

        <!-- Task Instructions -->
        <div>
          <label for="task-instructions-input" class="block text-slate-400 font-medium mb-1.5">Task Instructions (Инструкция / Запрос):</label>
          <textarea
            id="task-instructions-input"
            bind:value={taskText}
            rows="4"
            placeholder="Опишите задачу для команды саб-агентов..."
            class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-slate-100 focus:outline-none focus:border-indigo-500 font-mono text-xs resize-none"
          ></textarea>
        </div>

        <!-- Delayed Scheduling Option -->
        <div class="p-3 rounded-xl border border-slate-800 bg-slate-950/60 space-y-2">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" bind:checked={isScheduled} class="rounded bg-slate-900 border-slate-700 text-indigo-500 focus:ring-0" />
            <span class="text-slate-300 font-medium text-xs flex items-center gap-1.5">
              <span>⏰</span> Отложенное планирование задачи (Schedule for later)
            </span>
          </label>

          {#if isScheduled}
            <div class="grid grid-cols-2 gap-2 pt-1 border-t border-slate-800/60">
              <div>
                <label for="delay-select" class="block text-slate-400 text-[10px] mb-1">Запуск через:</label>
                <select id="delay-select" bind:value={delayMinutes} class="w-full bg-slate-900 border border-slate-800 rounded p-1.5 text-xs text-slate-200 cursor-pointer">
                  <option value={5}>Через 5 минут</option>
                  <option value={15}>Через 15 минут</option>
                  <option value={30}>Через 30 минут</option>
                  <option value={60}>Через 1 час</option>
                  <option value={120}>Через 2 часа</option>
                  <option value={0}>Указать точное время</option>
                </select>
              </div>

              {#if delayMinutes === 0}
                <div>
                  <label for="custom-time-input" class="block text-slate-400 text-[10px] mb-1">Точное время:</label>
                  <input id="custom-time-input" type="datetime-local" bind:value={customRunAt} class="w-full bg-slate-900 border border-slate-800 rounded p-1.5 text-xs text-slate-200" />
                </div>
              {:else}
                <div class="flex items-end pb-1.5">
                  <span class="text-[11px] text-amber-400/90 font-mono">
                    ≈ Запуск через {delayMinutes} мин
                  </span>
                </div>
              {/if}
            </div>
          {/if}
        </div>
      </div>

      <div class="px-6 py-4 border-t border-slate-800 flex justify-end gap-2 bg-slate-950/40">
        <button on:click={onClose} class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition cursor-pointer">
          Отмена
        </button>
        <button
          on:click={handleStart}
          disabled={isSubmitting || !taskText.trim()}
          class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium transition disabled:opacity-50 flex items-center gap-2 shadow-sm font-sans cursor-pointer"
        >
          {#if isScheduled}
            <span>⏰ Запланировать задачу</span>
          {:else}
            <span>🚀 Запустить задачу сейчас</span>
          {/if}
        </button>
      </div>
    </div>
  </div>
{/if}
