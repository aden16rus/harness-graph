<script lang="ts">
  import { onMount } from 'svelte';
  import { sessionStore, wsConnected } from '../stores/websocket';
  import { projectsList, activeProjectId, activeProject, fetchProjects, setActiveProject } from '../stores/projectStore';

  export let onOpenSettings: () => void = () => {};
  export let onNewSession: () => void = () => {};
  export let onOpenTasksHistory: () => void = () => {};

  let elapsedSeconds = 0;
  let timerInterval: any;
  let isStopping = false;

  onMount(() => {
    fetchProjects();
  });

  $: if ($sessionStore.status === 'running') {
    if (!timerInterval) {
      elapsedSeconds = 0;
      timerInterval = setInterval(() => {
        elapsedSeconds++;
      }, 1000);
    }
  } else {
    clearInterval(timerInterval);
    timerInterval = null;
  }

  function formatTime(sec: number): string {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  }

  async function handleStopTask() {
    if (isStopping) return;
    isStopping = true;
    try {
      await fetch('/api/session/stop', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: $sessionStore.sessionId }),
      });
      sessionStore.update(s => ({ ...s, status: 'failed' }));
    } catch (e) {
      console.error('Failed to stop task:', e);
    } finally {
      isStopping = false;
    }
  }
</script>

<header class="h-14 border-b border-slate-800 bg-slate-900/80 backdrop-blur px-4 flex items-center justify-between select-none z-20">
  <div class="flex items-center gap-3">
    <div class="flex items-center gap-2">
      <div class="w-7 h-7 rounded-lg bg-indigo-600 flex items-center justify-center font-bold text-white shadow-lg shadow-indigo-500/30 text-sm">
        H
      </div>
      <span class="font-bold text-slate-100 tracking-wide text-sm hidden sm:inline">HARNESS AGENT</span>
    </div>

    <div class="h-4 w-px bg-slate-800 mx-1"></div>

    <!-- WS Status -->
    <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {$wsConnected ? 'bg-emerald-950/80 text-emerald-400 border border-emerald-800/50' : 'bg-rose-950/80 text-rose-400 border border-rose-800/50'}">
      <span class="w-2 h-2 rounded-full {$wsConnected ? 'bg-emerald-400 animate-pulse' : 'bg-rose-400'}"></span>
      <span>{$wsConnected ? 'Online' : 'Connecting...'}</span>
    </div>

    <!-- Active Project Switcher Dropdown & Path Display -->
    <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-800/90 border border-slate-700 hover:border-indigo-500 text-xs transition" title={`Текущий проект: ${$activeProject?.name || ''}\nДиректория: ${$activeProject?.workspace_path || ''}`}>
      <span class="text-indigo-400">📁</span>
      <span class="text-slate-400 text-[11px] font-medium hidden md:inline">Проект:</span>
      <select
        value={$activeProjectId}
        on:change={(e) => setActiveProject(e.currentTarget.value)}
        class="bg-transparent text-slate-100 font-semibold cursor-pointer focus:outline-none max-w-[160px] truncate"
      >
        {#each $projectsList as p}
          <option value={p.id} class="bg-slate-900 text-slate-100">{p.name}</option>
        {/each}
      </select>
      {#if $activeProject?.workspace_path}
        <span class="text-[10px] font-mono text-slate-400 bg-slate-900/90 px-1.5 py-0.5 rounded border border-slate-800 max-w-[120px] truncate hidden lg:inline" title={$activeProject.workspace_path}>
          {$activeProject.workspace_path.replace('/workspace/', '').replace('/workspace', 'корень')}
        </span>
      {/if}
      <button
        type="button"
        on:click={onOpenSettings}
        class="text-[11px] text-indigo-400 hover:text-indigo-300 ml-0.5 px-1 hover:bg-slate-700/50 rounded transition cursor-pointer"
        title="Настройки проектов и рабочей директории"
      >
        ⚙️
      </button>
    </div>

    <!-- Live Execution Status Badge & Stop Task Button -->
    {#if $sessionStore.status === 'running'}
      <div class="flex items-center gap-2">
        <div class="flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-950/90 border border-cyan-500 text-cyan-300 font-semibold text-xs shadow-lg shadow-cyan-950/50 animate-pulse">
          <span class="w-2 h-2 rounded-full bg-cyan-400 animate-ping"></span>
          <span>ВЫПОЛНЕНИЕ ЗАДАЧИ</span>
          <span class="text-[10px] font-mono opacity-80">({$sessionStore.sessionId})</span>
        </div>
        <button
          on:click={handleStopTask}
          disabled={isStopping}
          class="flex items-center gap-1.5 px-3 py-1 rounded-full bg-rose-600 hover:bg-rose-500 active:bg-rose-700 disabled:opacity-50 text-white font-bold text-xs shadow-lg shadow-rose-950/50 transition cursor-pointer"
          title="Прервать выполнение текущей активной задачи"
        >
          <span>⏹</span>
          <span>{isStopping ? 'Остановка...' : 'Остановить'}</span>
        </button>
      </div>
    {:else if $sessionStore.status === 'completed'}
      <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-950/60 border border-emerald-700/60 text-emerald-300 text-xs font-medium">
        <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
        <span>Завершено</span>
        <span class="text-[10px] font-mono text-emerald-400/70">({$sessionStore.sessionId})</span>
      </div>
    {:else if $sessionStore.status === 'failed'}
      <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-rose-950/60 border border-rose-700/60 text-rose-300 text-xs font-medium">
        <span class="w-2 h-2 rounded-full bg-rose-400"></span>
        <span>Сбой / Остановлено</span>
        <span class="text-[10px] font-mono text-rose-400/70">({$sessionStore.sessionId})</span>
      </div>
    {:else}
      <span class="text-xs text-slate-400 font-mono px-2 py-0.5 rounded bg-slate-800/60 border border-slate-700/50">
        {$sessionStore.sessionId}
      </span>
    {/if}
  </div>

  <!-- Central Telemetry Counters -->
  <div class="flex items-center gap-6 text-xs">
    <!-- Active Agents -->
    <div class="flex items-center gap-2">
      <span class="text-slate-400">Agents:</span>
      <div class="flex items-center gap-1 font-mono font-medium">
        <span class="text-indigo-400 font-semibold">{$sessionStore.activeNodes}</span>
        <span class="text-slate-600">/</span>
        <span class="text-slate-300">{$sessionStore.completedNodes} done</span>
      </div>
    </div>

    <!-- Tokens Counter -->
    <div class="flex items-center gap-2">
      <span class="text-slate-400">Tokens:</span>
      <span class="font-mono font-semibold text-emerald-400">
        {($sessionStore.totalPromptTokens + $sessionStore.totalCompletionTokens).toLocaleString()}
      </span>
      <span class="text-slate-500 text-[10px] hidden md:inline">
        (P: {$sessionStore.totalPromptTokens.toLocaleString()} / C: {$sessionStore.totalCompletionTokens.toLocaleString()})
      </span>
    </div>

    <!-- Live Timer -->
    <div class="flex items-center gap-2">
      <span class="text-slate-400">Time:</span>
      <span class="font-mono font-medium text-amber-300 bg-slate-800 px-2 py-0.5 rounded border border-slate-700">
        {formatTime(elapsedSeconds)}
      </span>
    </div>
  </div>

  <!-- Right Actions -->
  <div class="flex items-center gap-2">
    <!-- Tasks & History Button -->
    <button
      on:click={onOpenTasksHistory}
      class="text-xs px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium transition border border-slate-700 flex items-center gap-1.5 shadow-sm cursor-pointer"
      title="История сессий и отложенные задачи"
    >
      <span>📋</span>
      <span>Задачи и История</span>
    </button>

    <button
      on:click={onNewSession}
      class="text-xs px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-medium transition shadow-sm flex items-center gap-1.5 cursor-pointer"
    >
      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
      </svg>
      <span>Run Task</span>
    </button>

    <button
      on:click={onOpenSettings}
      class="text-xs p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition border border-slate-700/60 cursor-pointer"
      title="Configure Project, Teams & LLM Profiles"
    >
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
    </button>
  </div>
</header>
