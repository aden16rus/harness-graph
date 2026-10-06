<script lang="ts">
  import { onMount } from 'svelte';
  import { sessionStore, loadSessionGraph } from '../stores/websocket';
  import { projectsList, activeProjectId } from '../stores/projectStore';

  export let isOpen = false;
  export let onClose: () => void = () => {};

  let activeTab: 'history' | 'scheduled' = 'history';
  let sessions: any[] = [];
  let scheduledTasks: any[] = [];
  let isLoading = false;
  let statusMessage = '';

  // Filters
  let filterProject = 'all';
  let filterStatus = 'all';
  let searchQuery = '';

  // Multi-selection
  let selectedSessionIds: string[] = [];

  // Old tasks cleanup dropdown state
  let showCleanupMenu = false;

  $: if (isOpen) {
    filterProject = $activeProjectId || 'all';
    selectedSessionIds = [];
    loadAll();
  }

  async function loadAll() {
    isLoading = true;
    await Promise.all([loadSessions(), loadScheduledTasks()]);
    isLoading = false;
  }

  async function loadSessions() {
    try {
      const url = filterProject !== 'all' ? `/api/sessions?project_id=${encodeURIComponent(filterProject)}` : '/api/sessions';
      const res = await fetch(url);
      if (res.ok) {
        sessions = await res.json();
      }
    } catch (e) {
      console.warn('Failed to fetch sessions:', e);
    }
  }

  async function loadScheduledTasks() {
    try {
      const res = await fetch('/api/tasks/scheduled');
      if (res.ok) {
        scheduledTasks = await res.json();
      }
    } catch (e) {
      console.warn('Failed to fetch scheduled tasks:', e);
    }
  }

  // Filtered sessions
  $: filteredSessions = sessions.filter(s => {
    if (filterProject !== 'all' && s.project_id !== filterProject) return false;
    if (filterStatus !== 'all' && s.status !== filterStatus) return false;
    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      const matchId = (s.id || '').toLowerCase().includes(q);
      const matchPrompt = (s.task_prompt || '').toLowerCase().includes(q);
      const matchProject = (s.project_name || '').toLowerCase().includes(q);
      if (!matchId && !matchPrompt && !matchProject) return false;
    }
    return true;
  });

  // Select all logic
  $: isAllSelected = filteredSessions.length > 0 && filteredSessions.every(s => selectedSessionIds.includes(s.id));

  function toggleSelectAll() {
    if (isAllSelected) {
      selectedSessionIds = [];
    } else {
      selectedSessionIds = filteredSessions.map(s => s.id);
    }
  }

  function toggleSelectSession(id: string) {
    if (selectedSessionIds.includes(id)) {
      selectedSessionIds = selectedSessionIds.filter(x => x !== id);
    } else {
      selectedSessionIds = [...selectedSessionIds, id];
    }
  }

  async function handleLoadGraph(sessionId: string) {
    statusMessage = 'Загрузка графа выполнения...';
    const ok = await loadSessionGraph(sessionId);
    if (ok) {
      statusMessage = '✓ Граф загружен!';
      setTimeout(() => {
        statusMessage = '';
        onClose();
      }, 500);
    } else {
      statusMessage = 'Ошибка загрузки графа';
      setTimeout(() => statusMessage = '', 2000);
    }
  }

  async function deleteSingleSession(sessionId: string) {
    if (!confirm(`Удалить задачу ${sessionId} из истории?`)) return;
    try {
      const res = await fetch(`/api/sessions?id=${encodeURIComponent(sessionId)}`, {
        method: 'DELETE',
      });
      if (res.ok) {
        statusMessage = '✓ Задача удалена';
        selectedSessionIds = selectedSessionIds.filter(id => id !== sessionId);
        await loadSessions();
        setTimeout(() => statusMessage = '', 1500);
      }
    } catch (e) {
      console.error('Failed to delete session:', e);
    }
  }

  async function deleteSelectedSessions() {
    if (selectedSessionIds.length === 0) return;
    if (!confirm(`Удалить выбранные задачи (${selectedSessionIds.length} шт.) из истории?`)) return;
    try {
      const idsParam = selectedSessionIds.join(',');
      const res = await fetch(`/api/sessions?id=${encodeURIComponent(idsParam)}`, {
        method: 'DELETE',
      });
      if (res.ok) {
        statusMessage = `✓ Удалено ${selectedSessionIds.length} задач`;
        selectedSessionIds = [];
        await loadSessions();
        setTimeout(() => statusMessage = '', 1500);
      }
    } catch (e) {
      console.error('Failed to batch delete sessions:', e);
    }
  }

  async function clearOlderThan(days: number) {
    showCleanupMenu = false;
    const label = days === 1 ? '1 дня' : `${days} дней`;
    const projSuffix = filterProject !== 'all' ? ` в текущем проекте` : '';
    if (!confirm(`Удалить все задачи старше ${label}${projSuffix}?`)) return;

    try {
      let url = `/api/sessions?action=clear_older_than&days=${days}`;
      if (filterProject !== 'all') {
        url += `&project_id=${encodeURIComponent(filterProject)}`;
      }
      const res = await fetch(url, { method: 'DELETE' });
      if (res.ok) {
        statusMessage = `✓ Задачи старше ${label} удалены`;
        selectedSessionIds = [];
        await loadSessions();
        setTimeout(() => statusMessage = '', 1500);
      }
    } catch (e) {
      console.error('Failed to clear old sessions:', e);
    }
  }

  async function clearFinishedSessions() {
    showCleanupMenu = false;
    const projSuffix = filterProject !== 'all' ? ` для текущего проекта` : '';
    if (!confirm(`Очистить все завершенные и сбойные задачи${projSuffix}?`)) return;
    try {
      let url = '/api/sessions?action=clear_finished';
      if (filterProject !== 'all') {
        url += `&project_id=${encodeURIComponent(filterProject)}`;
      }
      const res = await fetch(url, { method: 'DELETE' });
      if (res.ok) {
        statusMessage = '✓ Завершенные задачи очищены';
        selectedSessionIds = [];
        await loadSessions();
        setTimeout(() => statusMessage = '', 1500);
      }
    } catch (e) {
      console.error('Failed to clear finished sessions:', e);
    }
  }

  async function clearAllSessions() {
    showCleanupMenu = false;
    const projSuffix = filterProject !== 'all' ? ` в текущем проекте` : ' (ВСЯ ИСТОРИЯ)';
    if (!confirm(`ВНИМАНИЕ: Очистить абсолютно ВСЕ задачи${projSuffix}? Это действие необратимо.`)) return;
    try {
      let url = '/api/sessions?action=clear_all';
      if (filterProject !== 'all') {
        url += `&project_id=${encodeURIComponent(filterProject)}`;
      }
      const res = await fetch(url, { method: 'DELETE' });
      if (res.ok) {
        statusMessage = '✓ История задач очищена';
        selectedSessionIds = [];
        await loadSessions();
        setTimeout(() => statusMessage = '', 1500);
      }
    } catch (e) {
      console.error('Failed to clear all sessions:', e);
    }
  }

  async function cancelScheduledTask(taskId: string) {
    try {
      await fetch(`/api/tasks/scheduled?id=${encodeURIComponent(taskId)}`, { method: 'DELETE' });
      await loadScheduledTasks();
    } catch (e) {
      console.error(e);
    }
  }

  async function runScheduledNow(task: any) {
    try {
      await cancelScheduledTask(task.id);
      const sessionId = 'sess_' + Math.random().toString(36).substring(2, 9);
      await fetch('/api/session/start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          session_id: sessionId,
          task: task.task,
          project_id: task.project_id,
          team_id: task.team_id,
        }),
      });
      sessionStore.update(s => ({ ...s, sessionId, status: 'running' }));
      onClose();
    } catch (e) {
      console.error(e);
    }
  }

  function formatDuration(ms: number) {
    if (!ms) return '0s';
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
  }
</script>

{#if isOpen}
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/85 backdrop-blur-sm select-none">
    <div class="w-full max-w-5xl rounded-2xl bg-slate-900 border border-slate-800 shadow-2xl flex flex-col max-h-[90vh] overflow-hidden text-slate-100">
      <!-- Header -->
      <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between bg-slate-950/60">
        <div class="flex items-center gap-2.5">
          <span class="text-xl">📋</span>
          <div>
            <h2 class="font-bold text-base text-slate-100">Управление задачами и история сессий</h2>
            <p class="text-[11px] text-slate-400">Просмотр, фильтрация и пакетное удаление старых задач для разгрузки списка</p>
          </div>
        </div>
        <button on:click={onClose} class="text-slate-500 hover:text-slate-300 text-lg cursor-pointer">✕</button>
      </div>

      <!-- Navigation Tabs & Primary Actions -->
      <div class="flex items-center justify-between border-b border-slate-800 text-xs px-6 bg-slate-950/40">
        <div class="flex">
          <button
            on:click={() => activeTab = 'history'}
            class="py-3 px-4 border-b-2 font-medium transition flex items-center gap-2 cursor-pointer {activeTab === 'history' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-slate-200'}"
          >
            <span>📜 История сессий ({filteredSessions.length}/{sessions.length})</span>
          </button>
          <button
            on:click={() => activeTab = 'scheduled'}
            class="py-3 px-4 border-b-2 font-medium transition flex items-center gap-2 cursor-pointer {activeTab === 'scheduled' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-slate-200'}"
          >
            <span>⏰ Отложенные задачи ({scheduledTasks.length})</span>
          </button>
        </div>

        {#if activeTab === 'history' && sessions.length > 0}
          <div class="flex items-center gap-2 relative">
            <!-- Batch delete selected button -->
            {#if selectedSessionIds.length > 0}
              <button
                on:click={deleteSelectedSessions}
                class="px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-500 active:bg-rose-700 text-white font-semibold text-[11px] transition shadow flex items-center gap-1.5 cursor-pointer animate-pulse"
              >
                <span>🗑️</span>
                <span>Удалить выбранные ({selectedSessionIds.length})</span>
              </button>
            {/if}

            <!-- Quick Cleanup Dropdown Button -->
            <div class="relative">
              <button
                on:click={() => showCleanupMenu = !showCleanupMenu}
                class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-semibold transition border border-slate-700 flex items-center gap-1.5 cursor-pointer shadow-sm"
              >
                <span>🧹</span>
                <span>Очистить старые задачи ▾</span>
              </button>

              {#if showCleanupMenu}
                <div class="absolute right-0 mt-1.5 w-60 rounded-xl bg-slate-900 border border-slate-700 shadow-2xl py-1 z-30 text-xs space-y-0.5">
                  <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500 border-b border-slate-800">
                    Очистка по возрасту:
                  </div>
                  <button
                    on:click={() => clearOlderThan(1)}
                    class="w-full text-left px-3 py-2 text-slate-200 hover:bg-slate-800 transition flex items-center justify-between cursor-pointer"
                  >
                    <span>Старше 1 дня</span>
                    <span class="text-[10px] text-slate-500">(&gt; 24 ч)</span>
                  </button>
                  <button
                    on:click={() => clearOlderThan(3)}
                    class="w-full text-left px-3 py-2 text-slate-200 hover:bg-slate-800 transition flex items-center justify-between cursor-pointer"
                  >
                    <span>Старше 3 дней</span>
                    <span class="text-[10px] text-slate-500">(&gt; 72 ч)</span>
                  </button>
                  <button
                    on:click={() => clearOlderThan(7)}
                    class="w-full text-left px-3 py-2 text-slate-200 hover:bg-slate-800 transition flex items-center justify-between cursor-pointer"
                  >
                    <span>Старше 7 дней</span>
                    <span class="text-[10px] text-slate-500">(&gt; 1 нед)</span>
                  </button>

                  <div class="border-t border-slate-800 my-1"></div>

                  <button
                    on:click={clearFinishedSessions}
                    class="w-full text-left px-3 py-2 text-amber-300 hover:bg-slate-800 transition flex items-center gap-2 cursor-pointer"
                  >
                    <span>🧹</span>
                    <span>Очистить все завершенные</span>
                  </button>
                  <button
                    on:click={clearAllSessions}
                    class="w-full text-left px-3 py-2 text-rose-400 hover:bg-rose-950/40 transition flex items-center gap-2 cursor-pointer"
                  >
                    <span>🗑️</span>
                    <span>Очистить всю историю</span>
                  </button>
                </div>
              {/if}
            </div>
          </div>
        {/if}
      </div>

      <!-- Filters Bar (Only on history tab) -->
      {#if activeTab === 'history'}
        <div class="px-6 py-2.5 bg-slate-950/60 border-b border-slate-800/80 flex items-center justify-between gap-3 flex-wrap text-xs">
          <div class="flex items-center gap-3 flex-wrap flex-1 min-w-0">
            <!-- Select All Checkbox -->
            <label class="flex items-center gap-1.5 cursor-pointer text-slate-300 font-medium shrink-0">
              <input
                type="checkbox"
                checked={isAllSelected}
                on:change={toggleSelectAll}
                class="rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-0"
              />
              <span class="text-[11px]">Выбрать все</span>
            </label>

            <div class="h-4 w-px bg-slate-800"></div>

            <!-- Project Filter Dropdown -->
            <div class="flex items-center gap-1.5 shrink-0">
              <span class="text-slate-400 text-[11px]">Проект:</span>
              <select
                bind:value={filterProject}
                on:change={loadSessions}
                class="bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1 text-xs text-slate-200 focus:outline-none focus:border-indigo-500 cursor-pointer"
              >
                <option value="all">Все проекты ({projectsList ? $projectsList.length : ''})</option>
                {#each $projectsList as p}
                  <option value={p.id}>{p.name} {p.id === $activeProjectId ? '(текущий)' : ''}</option>
                {/each}
              </select>
            </div>

            <!-- Status Filter -->
            <div class="flex items-center gap-1.5 shrink-0">
              <span class="text-slate-400 text-[11px]">Статус:</span>
              <select
                bind:value={filterStatus}
                class="bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1 text-xs text-slate-200 focus:outline-none focus:border-indigo-500 cursor-pointer"
              >
                <option value="all">Все статусы</option>
                <option value="completed">Completed (Завершенные)</option>
                <option value="running">Running (В процессе)</option>
                <option value="failed">Failed (Сбой / Отмена)</option>
              </select>
            </div>
          </div>

          <!-- Search Input -->
          <div class="w-56 shrink-0">
            <input
              type="text"
              bind:value={searchQuery}
              placeholder="Поиск по тексту или ID..."
              class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500"
            />
          </div>
        </div>
      {/if}

      <!-- Content -->
      <div class="flex-1 overflow-y-auto p-6 space-y-3 font-sans text-xs">
        {#if isLoading}
          <div class="py-12 text-center text-slate-400 text-xs animate-pulse">
            Загрузка списка задач...
          </div>
        {:else if activeTab === 'history'}
          {#if filteredSessions.length === 0}
            <div class="py-14 text-center text-slate-500 text-xs space-y-1">
              <p class="font-medium text-slate-400">Нет задач, соответствующих фильтрам.</p>
              <p class="text-[11px] text-slate-500">Попробуйте сбросить фильтры или запустить новую задачу.</p>
            </div>
          {:else}
            <div class="space-y-2.5">
              {#each filteredSessions as s}
                {@const isSelected = selectedSessionIds.includes(s.id)}
                <div class="p-3.5 rounded-xl border transition flex items-start justify-between gap-3.5 {isSelected ? 'bg-indigo-950/40 border-indigo-500 ring-1 ring-indigo-500/40' : 'bg-slate-950/60 border-slate-800 hover:border-slate-700'}">
                  <!-- Checkbox -->
                  <div class="pt-1 shrink-0">
                    <input
                      type="checkbox"
                      checked={isSelected}
                      on:change={() => toggleSelectSession(s.id)}
                      class="rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-0 cursor-pointer"
                    />
                  </div>

                  <!-- Details -->
                  <div class="flex-1 min-w-0 space-y-1.5">
                    <div class="flex items-center gap-2 flex-wrap">
                      <span class="font-mono text-xs font-semibold text-slate-200">{s.id}</span>

                      <!-- Status badge -->
                      {#if s.status === 'completed'}
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-950/80 text-emerald-400 border border-emerald-800/80">
                          ● Завершено
                        </span>
                      {:else if s.status === 'running'}
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-950/80 text-cyan-400 border border-cyan-800/80 animate-pulse">
                          ● Выполняется
                        </span>
                      {:else}
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-950/80 text-rose-400 border border-rose-800/80">
                          ● Сбой
                        </span>
                      {/if}

                      <!-- Project badge -->
                      <span class="px-2 py-0.5 rounded bg-slate-900 border border-slate-800 text-indigo-300 text-[10px] font-medium flex items-center gap-1">
                        <span>📁</span>
                        <span>{s.project_name || s.project_id}</span>
                      </span>

                      <!-- Team badge -->
                      <span class="px-2 py-0.5 rounded bg-slate-900 border border-slate-800 text-slate-400 text-[10px]">
                        👥 {s.team_name || s.team_id}
                      </span>
                    </div>

                    <!-- Prompt Preview -->
                    <p class="text-xs text-slate-300 font-sans line-clamp-2 bg-slate-900/60 p-2 rounded border border-slate-800/60">
                      {s.task_prompt || 'Задача не указана'}
                    </p>

                    <!-- Metrics -->
                    <div class="flex items-center gap-4 text-[11px] text-slate-400 pt-0.5 flex-wrap">
                      {#if s.lead_agent_name}
                        <span class="flex items-center gap-1">
                          <span>👑</span> {s.lead_agent_name}
                        </span>
                      {/if}
                      <span>Узлов: <strong>{s.node_count || 1}</strong></span>
                      <span>Токены: <strong>{(s.total_prompt_tokens + s.total_completion_tokens).toLocaleString()}</strong></span>
                      <span>Время: <strong>{formatDuration(s.total_duration_ms)}</strong></span>
                      <span class="text-slate-500 font-mono text-[10px]">{s.started_at}</span>
                    </div>
                  </div>

                  <!-- Actions -->
                  <div class="flex items-center gap-2 shrink-0 pt-0.5">
                    <button
                      type="button"
                      on:click={() => handleLoadGraph(s.id)}
                      class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-semibold transition text-xs flex items-center gap-1.5 shadow-sm cursor-pointer"
                      title="Загрузить граф этой сессии на рабочий экран"
                    >
                      <span>📊</span>
                      <span>Граф</span>
                    </button>
                    <button
                      type="button"
                      on:click={() => deleteSingleSession(s.id)}
                      class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-900/70 hover:text-rose-300 text-slate-400 text-xs transition border border-slate-700 hover:border-rose-700/60 cursor-pointer"
                      title="Удалить эту задачу из истории"
                    >
                      🗑️
                    </button>
                  </div>
                </div>
              {/each}
            </div>
          {/if}

        {:else if activeTab === 'scheduled'}
          {#if scheduledTasks.length === 0}
            <div class="py-12 text-center text-slate-500 text-xs">
              Нет запланированных отложенных задач. Вы можете запланировать задачу через модальное окно «Run Task».
            </div>
          {:else}
            <div class="space-y-2.5">
              {#each scheduledTasks as task}
                <div class="p-3.5 rounded-xl border border-slate-800 bg-slate-950/60 flex items-center justify-between gap-4">
                  <div class="space-y-1 flex-1">
                    <div class="flex items-center gap-2">
                      <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-950 text-amber-400 border border-amber-800">
                        ⏰ Запланировано
                      </span>
                      <span class="font-bold text-xs text-slate-200">Запуск: {task.run_at}</span>
                      <span class="text-[11px] text-slate-400">Проект: <strong class="text-slate-300">{task.project_name}</strong></span>
                      <span class="text-[11px] text-slate-400">Команда: <strong class="text-slate-300">{task.team_name}</strong></span>
                    </div>
                    <p class="text-xs text-slate-300 font-sans p-2 rounded bg-slate-900 border border-slate-800">
                      {task.task}
                    </p>
                  </div>

                  <div class="flex items-center gap-2 shrink-0">
                    <button
                      type="button"
                      on:click={() => runScheduledNow(task)}
                      class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold transition cursor-pointer"
                    >
                      ⚡ Запустить сейчас
                    </button>
                    <button
                      type="button"
                      on:click={() => cancelScheduledTask(task.id)}
                      class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-rose-900/60 hover:text-rose-300 text-slate-400 text-xs font-medium border border-slate-700 transition cursor-pointer"
                    >
                      ✕ Отменить
                    </button>
                  </div>
                </div>
              {/each}
            </div>
          {/if}
        {/if}
      </div>

      <!-- Footer -->
      <div class="px-6 py-4 border-t border-slate-800 flex items-center justify-between bg-slate-950/40">
        <div>
          {#if statusMessage}
            <span class="text-xs font-semibold text-emerald-400 animate-pulse">{statusMessage}</span>
          {/if}
        </div>
        <button on:click={onClose} class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium transition text-xs cursor-pointer">
          Закрыть
        </button>
      </div>
    </div>
  </div>
{/if}
