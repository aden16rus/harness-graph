<script lang="ts">
  import { Handle, Position } from '@xyflow/svelte';
  import type { AgentNodeData } from '../types';

  export let data: AgentNodeData = {
    id: '',
    agentId: '',
    agentName: 'Agent',
    role: 'general',
    status: 'pending',
    depth: 0,
    inputPrompt: '',
    outputResult: '',
    promptTokens: 0,
    completionTokens: 0,
    durationMs: 0,
    startedAt: '',
    dialog: [],
    toolCalls: [],
  };

  function getStatusClasses(status: string = 'pending'): { border: string; bg: string; dot: string; text: string } {
    switch (status) {
      case 'active':
        return {
          border: 'border-cyan-500 shadow-cyan-500/20 shadow-lg ring-1 ring-cyan-500/50',
          bg: 'bg-slate-900/90',
          dot: 'bg-cyan-400 animate-pulse',
          text: 'text-cyan-400',
        };
      case 'calling_tool':
        return {
          border: 'border-violet-500 shadow-violet-500/20 shadow-lg ring-1 ring-violet-500/50',
          bg: 'bg-slate-900/90',
          dot: 'bg-violet-400 animate-ping',
          text: 'text-violet-400',
        };
      case 'waiting_human':
        return {
          border: 'border-amber-500 shadow-amber-500/30 shadow-lg ring-2 ring-amber-500 animate-bounce',
          bg: 'bg-amber-950/40',
          dot: 'bg-amber-400 animate-ping',
          text: 'text-amber-400 font-bold',
        };
      case 'completed':
        return {
          border: 'border-emerald-500/60 shadow-emerald-500/10 shadow',
          bg: 'bg-slate-900/90',
          dot: 'bg-emerald-400',
          text: 'text-emerald-400',
        };
      case 'failed':
        return {
          border: 'border-rose-500 shadow-rose-500/20 shadow-lg',
          bg: 'bg-rose-950/20',
          dot: 'bg-rose-500',
          text: 'text-rose-400',
        };
      default:
        return {
          border: 'border-slate-700',
          bg: 'bg-slate-900/80',
          dot: 'bg-slate-500',
          text: 'text-slate-400',
        };
    }
  }

  $: styles = getStatusClasses(data?.status);
</script>

<div class="relative w-64 rounded-xl border {styles.border} {styles.bg} p-3.5 transition-all text-xs select-none backdrop-blur-md">
  {#if (data?.depth || 0) > 0}
    <Handle type="target" position={Position.Top} class="!w-2.5 !h-2.5 !bg-indigo-400 !border-slate-900" />
  {/if}

  <!-- Header -->
  <div class="flex items-start justify-between gap-2 mb-2">
    <div>
      <div class="font-bold text-slate-100 text-sm tracking-tight flex items-center gap-1.5">
        <span>{data?.agentName || 'Agent'}</span>
      </div>
      <span class="inline-block uppercase tracking-wider text-[10px] font-semibold text-slate-400 bg-slate-800/80 px-1.5 py-0.5 rounded border border-slate-700/60 mt-0.5">
        {data?.role || 'general'}
      </span>
    </div>

    <!-- Status Pill -->
    <div class="flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-slate-950/80 border border-slate-800">
      <span class="w-1.5 h-1.5 rounded-full {styles.dot}"></span>
      <span class="text-[10px] uppercase font-mono tracking-tight {styles.text}">
        {(data?.status || 'pending').replace('_', ' ')}
      </span>
    </div>
  </div>

  <!-- Active Tool Widget -->
  {#if data?.activeTool}
    <div class="my-2 p-1.5 rounded-lg bg-violet-950/60 border border-violet-800/60 flex items-center gap-2 text-violet-200">
      <svg class="w-3.5 h-3.5 animate-spin text-violet-400 shrink-0" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
      </svg>
      <span class="font-mono text-[11px] truncate font-medium">
        {data.activeTool}
      </span>
    </div>
  {/if}

  <!-- Waiting Human Alert Card -->
  {#if data?.status === 'waiting_human'}
    <div class="my-2 p-2 rounded-lg bg-amber-950/80 border border-amber-600/70 text-amber-200 text-[11px] font-medium flex items-center gap-2">
      <span class="text-base">⚠️</span>
      <span class="truncate">Operator input required</span>
    </div>
  {/if}

  <!-- Mini TODO Progress Pill & Current Active Step -->
  {#if data?.todos && data.todos.length > 0}
    {@const inProgressItem = data.todos.find(t => t.status === 'in_progress')}
    {@const currentStep = inProgressItem || data.todos.find(t => t.status === 'pending')}
    {@const completed = data.todos.filter(t => t.status === 'completed').length}
    {@const inProg = data.todos.filter(t => t.status === 'in_progress').length}
    {@const total = data.todos.length}
    <div class="my-1.5 p-1.5 rounded-lg bg-slate-950/90 border border-indigo-900/50 space-y-1 text-[10px]">
      <div class="flex items-center justify-between font-medium">
        <div class="flex items-center gap-1.5">
          <span>📋</span>
          <span class="text-slate-300">TODO: {completed}/{total}</span>
          {#if inProg > 0}
            <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse"></span>
          {/if}
        </div>
        <div class="w-14 bg-slate-800 h-1.5 rounded-full overflow-hidden">
          <div class="bg-emerald-400 h-full rounded-full transition-all" style="width: {Math.round((completed / total) * 100)}%"></div>
        </div>
      </div>

      {#if currentStep}
        <div class="flex items-start gap-1 p-1 rounded bg-indigo-950/50 border border-indigo-800/50 text-[10px] text-slate-200 leading-tight">
          <span class="shrink-0 {currentStep.status === 'in_progress' ? 'text-indigo-400 font-bold animate-pulse' : 'text-slate-500'}">
            {currentStep.status === 'in_progress' ? '►' : '•'}
          </span>
          <span class="truncate font-medium text-slate-100" title={currentStep.content}>
            {currentStep.content}
          </span>
        </div>
      {/if}
    </div>
  {/if}

  <!-- Footer Telemetry: Context Window & Cumulative API Traffic -->
  <div class="mt-2.5 pt-2 border-t border-slate-800/80 flex items-center justify-between text-[10px] text-slate-400 font-mono">
    <div class="flex items-center gap-2">
      <div class="flex items-center gap-0.5" title="Текущий размер контекстного окна ноды">
        <span class="text-slate-500">Ctx:</span>
        <span class="text-cyan-400 font-semibold">{((data?.contextTokens || 0)).toLocaleString()}</span>
      </div>
      <div class="flex items-center gap-0.5" title="Суммарно потрачено токенов в API (отправлено + получено)">
        <span class="text-slate-500">API:</span>
        <span class="text-emerald-400 font-semibold">{((data?.promptTokens || 0) + (data?.completionTokens || 0)).toLocaleString()}</span>
      </div>
    </div>

    {#if (data?.durationMs || 0) > 0}
      <div class="flex items-center gap-1">
        <span class="text-slate-300">{(data.durationMs / 1000).toFixed(1)}s</span>
      </div>
    {/if}
  </div>

  <Handle type="source" position={Position.Bottom} class="!w-2.5 !h-2.5 !bg-indigo-400 !border-slate-900" />
</div>
