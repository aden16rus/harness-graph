<script lang="ts">
  import { nodesStore, selectedNodeId, wsManager, sessionStore, sendFollowupMessage } from '../stores/websocket';
  import type { AgentNodeData } from '../types';

  let activeTab: 'dialog' | 'human' = 'dialog';
  let humanAnswerText = '';
  let followupText = '';
  let isSendingFollowup = false;

  $: currentNode = $selectedNodeId ? $nodesStore.get($selectedNodeId) : null;

  // Auto-switch to human tab if current node is waiting human
  $: if (currentNode && currentNode.status === 'waiting_human') {
    activeTab = 'human';
  }

  function submitHumanAnswer(answer: string) {
    if (!currentNode || !answer.trim()) return;
    wsManager.sendHumanAnswer(currentNode.id, answer.trim());
    humanAnswerText = '';
  }

  async function handleSendFollowup() {
    if (!followupText.trim()) return;
    const sessId = $sessionStore.sessionId;
    if (!sessId || sessId === 'sess_default') {
      alert('Нет активной сессии для отправки сообщения.');
      return;
    }
    const targetNodeId = currentNode?.id;
    const text = followupText.trim();
    followupText = '';
    isSendingFollowup = true;

    // Optimistically append user message to current node dialog so dialog immediately continues!
    if (currentNode) {
      nodesStore.update(map => {
        const updated = new Map(map);
        const n = updated.get(currentNode.id);
        if (n) {
          let maxStep = 0;
          for (const d of n.dialog) {
            if (d.step) maxStep = Math.max(maxStep, d.step);
          }
          updated.set(n.id, {
            ...n,
            status: 'active',
            dialog: [
              ...n.dialog,
              {
                role: 'user',
                step: maxStep + 1,
                text,
                timestamp: new Date().toISOString(),
              },
            ],
          });
        }
        return updated;
      });
    }

    try {
      const ok = await sendFollowupMessage(sessId, text, targetNodeId);
      if (!ok) {
        alert('Ошибка при отправке сообщения менеджеру.');
      }
    } finally {
      isSendingFollowup = false;
    }
  }

  function getSkillIcon(name: string = ''): string {
    const n = (name || '').toLowerCase();
    if (n.includes('read_file')) return '📄';
    if (n.includes('write_file')) return '✏️';
    if (n.includes('list_dir')) return '📁';
    if (n.includes('host_exec')) return '💻';
    if (n.includes('docker_exec')) return '🐳';
    if (n.includes('call_sub_agent')) return '🤖';
    if (n.includes('ask_human_expert')) return '👤';
    if (n.includes('browse_link')) return '🌐';
    return '⚙️';
  }

  function getArgsPreview(args: any): string {
    if (!args || typeof args !== 'object') return '';
    if (args.command) return String(args.command);
    if (args.path) return String(args.path);
    if (args.cmd) return Array.isArray(args.cmd) ? args.cmd.join(' ') : String(args.cmd);
    if (args.agent_role) return `role: ${args.agent_role}`;
    if (args.question) return String(args.question);
    const keys = Object.keys(args);
    if (keys.length === 0) return '';
    return `${keys[0]}: ${String(args[keys[0]]).slice(0, 30)}`;
  }

  // Helper to get step number if available or compute from dialog index
  function getStepLabel(msg: any, index: number, dialog: any[]): string | null {
    if (msg.step && msg.step > 0) {
      return `Шаг #${msg.step}`;
    }
    // For historical dialogs without explicit step field
    if (msg.role === 'thinking' || msg.role === 'tool' || msg.role === 'assistant') {
      let turn = 1;
      for (let i = 0; i < index; i++) {
        if (dialog[i].role === 'assistant' || dialog[i].role === 'tool') {
          turn++;
        }
      }
      return `Шаг #${Math.ceil(turn / 2)}`;
    }
    return null;
  }
  function getCommandString(args: any): string {
    if (!args) return '';
    if (args.command) return String(args.command);
    if (args.cmd) {
      if (Array.isArray(args.cmd)) return args.cmd.join(' ');
      if (typeof args.cmd === 'object') return Object.values(args.cmd).join(' ');
      return String(args.cmd);
    }
    return '';
  }

  function getCleanTerminalOutput(output: string): { stdout: string; stderr: string; exitCode?: number } {
    if (!output) return { stdout: '', stderr: '' };
    let stdout = '';
    let stderr = '';
    let exitCode: number | undefined;

    const exitMatch = output.match(/Exit Code:\s*(\d+)/i);
    if (exitMatch) {
      exitCode = parseInt(exitMatch[1], 10);
    }

    if (output.includes('--- STDOUT ---') || output.includes('--- STDERR ---')) {
      const stdoutMatch = output.match(/--- STDOUT ---\n([\s\S]*?)(?=--- STDERR ---|$)/);
      if (stdoutMatch) {
        stdout = stdoutMatch[1].trimEnd();
      }
      const stderrMatch = output.match(/--- STDERR ---\n([\s\S]*?)$/);
      if (stderrMatch) {
        stderr = stderrMatch[1].trimEnd();
      }
    } else {
      stdout = output.trim();
    }

    return { stdout, stderr, exitCode };
  }

  function parseListDirOutput(output: string): { dir: string; items: { type: 'dir' | 'file'; name: string; size?: string }[] } {
    if (!output) return { dir: '', items: [] };
    const lines = output.split('\n');
    let dir = '';
    const items: { type: 'dir' | 'file'; name: string; size?: string }[] = [];

    for (const line of lines) {
      const trimmed = line.trim();
      if (trimmed.startsWith('Directory listing for')) {
        dir = trimmed.replace('Directory listing for', '').replace(':', '').trim() || '.';
        continue;
      }
      if (trimmed.startsWith('[DIR]')) {
        const parts = trimmed.substring(5).trim().split(/\s+/);
        if (parts[0]) items.push({ type: 'dir', name: parts[0] });
      } else if (trimmed.startsWith('[FILE]')) {
        const rest = trimmed.substring(6).trim();
        const match = rest.match(/^(\S+)\s+(.+)$/);
        if (match) {
          items.push({ type: 'file', name: match[1], size: match[2] });
        } else if (rest) {
          items.push({ type: 'file', name: rest });
        }
      }
    }
    return { dir, items };
  }

  function parseReadFileLines(output: string): { header: string; lines: { num: number; code: string }[]; footer?: string } {
    if (!output) return { header: '', lines: [] };
    const rawLines = output.split('\n');
    let header = '';
    let footer = '';
    const lines: { num: number; code: string }[] = [];

    for (const line of rawLines) {
      if (line.startsWith('--- File:')) {
        header = line.replace(/^-+\s*|\s*-+$/g, '');
        continue;
      }
      if (line.startsWith('[... ') && line.includes('more lines')) {
        footer = line;
        continue;
      }
      const match = line.match(/^\s*(\d+)\s*\|\s?(.*)$/);
      if (match) {
        lines.push({ num: parseInt(match[1], 10), code: match[2] });
      } else if (line.trim() !== '') {
        lines.push({ num: lines.length + 1, code: line });
      }
    }
    return { header, lines, footer };
  }

  function getTodoItemsFromMessage(msg: any): { content: string; status: 'completed' | 'in_progress' | 'pending' }[] {
    if (msg.args && Array.isArray(msg.args.todos) && msg.args.todos.length > 0) {
      return msg.args.todos.map((t: any) => ({
        content: t.content || t.task || '',
        status: t.status || 'pending',
      }));
    }
    const items: any[] = [];
    const text = msg.output || msg.text || '';
    const lines = text.split('\n');
    for (const l of lines) {
      const trimmed = l.trim();
      if (trimmed.includes('[COMPLETED]')) {
        items.push({ content: trimmed.replace(/.*\[COMPLETED\]\s*/, ''), status: 'completed' });
      } else if (trimmed.includes('[IN_PROGRESS]')) {
        items.push({ content: trimmed.replace(/.*\[IN_PROGRESS\]\s*/, ''), status: 'in_progress' });
      } else if (trimmed.includes('[PENDING]')) {
        items.push({ content: trimmed.replace(/.*\[PENDING\]\s*/, ''), status: 'pending' });
      }
    }
    return items;
  }
</script>

<aside class="w-full border-l border-slate-800 bg-slate-900/95 flex flex-col h-full shadow-2xl backdrop-blur select-none z-10 text-slate-100">
  {#if !currentNode}
    <div class="flex-1 flex flex-col items-center justify-center p-6 text-center text-slate-500">
      <div class="w-12 h-12 rounded-xl bg-slate-800/80 border border-slate-700/60 flex items-center justify-center mb-3 text-lg">
        🔍
      </div>
      <h3 class="font-medium text-slate-400 text-sm">Node Inspector</h3>
      <p class="text-xs text-slate-600 mt-1">Кликните на любой узел графа, чтобы просмотреть диалог, шаги выполнения, вызовы инструментов или ответить на запрос эксперта.</p>
    </div>
  {:else}
    <!-- Node Header -->
    <div class="p-4 border-b border-slate-800 bg-slate-950/40">
      <div class="flex items-start justify-between">
        <div>
          <h2 class="font-bold text-base text-slate-100 leading-snug">{currentNode.agentName}</h2>
          <div class="flex items-center gap-2 mt-1">
            <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded bg-indigo-950 text-indigo-400 border border-indigo-800/50">
              {currentNode.role}
            </span>
            <span class="text-[11px] font-mono text-slate-500">
              Depth: {currentNode.depth}
            </span>
          </div>
        </div>

        <button
          on:click={() => selectedNodeId.set(null)}
          class="p-1 text-slate-500 hover:text-slate-300 rounded hover:bg-slate-800 transition cursor-pointer"
        >
          ✕
        </button>
      </div>

      <!-- Quick Metrics Strip -->
      <div class="mt-3 grid grid-cols-3 gap-2 text-center text-[11px] font-mono bg-slate-900 p-2 rounded-lg border border-slate-800">
        <div>
          <span class="text-slate-500 block text-[9px] uppercase">Tokens</span>
          <span class="font-semibold text-emerald-400">
            {((currentNode.promptTokens || 0) + (currentNode.completionTokens || 0)).toLocaleString()}
          </span>
        </div>
        <div>
          <span class="text-slate-500 block text-[9px] uppercase">Latency</span>
          <span class="font-semibold text-amber-300">
            {currentNode.durationMs ? `${(currentNode.durationMs / 1000).toFixed(1)}s` : '-'}
          </span>
        </div>
        <div>
          <span class="text-slate-500 block text-[9px] uppercase">Status</span>
          <span class="font-semibold text-indigo-300 capitalize truncate">
            {currentNode.status.replace('_', ' ')}
          </span>
        </div>
      </div>

      <!-- Task Plan & Real-time Progress (TODO List) -->
      {#if (currentNode.todos && currentNode.todos.length > 0) || currentNode.expectedOutcome}
        {@const completedCount = (currentNode.todos || []).filter(t => t.status === 'completed').length}
        {@const inProgressCount = (currentNode.todos || []).filter(t => t.status === 'in_progress').length}
        {@const totalTodos = (currentNode.todos || []).length}
        {@const progressPercent = totalTodos > 0 ? Math.round((completedCount / totalTodos) * 100) : 0}

        <div class="mt-3 p-3 rounded-xl bg-slate-900/90 border border-indigo-900/50 space-y-2.5 shadow-sm">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-1.5 font-bold text-xs text-indigo-300">
              <span>📋</span>
              <span>План задачи и прогресс (TODO)</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="text-[11px] font-mono font-semibold {completedCount === totalTodos && totalTodos > 0 ? 'text-emerald-400' : 'text-slate-300'}">
                {completedCount}/{totalTodos} ({progressPercent}%)
              </span>
            </div>
          </div>

          <!-- Progress Bar -->
          <div class="w-full bg-slate-950 h-2 rounded-full overflow-hidden border border-slate-800">
            <div
              class="h-full transition-all duration-300 rounded-full {completedCount === totalTodos && totalTodos > 0 ? 'bg-emerald-500' : inProgressCount > 0 ? 'bg-indigo-500' : 'bg-slate-700'}"
              style="width: {progressPercent}%"
            ></div>
          </div>

          {#if currentNode.expectedOutcome}
            <div class="p-2 rounded-lg bg-indigo-950/30 border border-indigo-800/40 text-[11px] text-slate-200">
              <span class="text-[10px] uppercase font-bold text-indigo-400 block mb-0.5">🎯 Ожидаемый результат:</span>
              <span class="leading-relaxed whitespace-pre-wrap">{currentNode.expectedOutcome}</span>
            </div>
          {/if}

          <!-- Checklist Items -->
          {#if totalTodos > 0}
            <div class="space-y-1.5 pt-1 max-h-52 overflow-y-auto">
              {#each currentNode.todos as item, idx}
                <div class="flex items-start gap-2 p-2 rounded-lg text-xs transition border {item.status === 'completed' ? 'bg-emerald-950/20 border-emerald-900/40 text-slate-300' : item.status === 'in_progress' ? 'bg-indigo-950/40 border-indigo-700/60 text-slate-100 ring-1 ring-indigo-500/30 shadow-sm' : 'bg-slate-950/40 border-slate-800/70 text-slate-400'}">
                  <!-- Status Indicator Icon -->
                  <div class="mt-0.5 shrink-0">
                    {#if item.status === 'completed'}
                      <div class="w-4 h-4 rounded-full bg-emerald-500/20 border border-emerald-500/60 flex items-center justify-center text-[10px] text-emerald-400 font-bold">
                        ✓
                      </div>
                    {:else if item.status === 'in_progress'}
                      <div class="w-4 h-4 rounded-full bg-indigo-500/20 border border-indigo-500/60 flex items-center justify-center text-[9px] text-indigo-300 animate-pulse">
                        ⏳
                      </div>
                    {:else}
                      <div class="w-4 h-4 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-[10px] text-slate-500">
                        ○
                      </div>
                    {/if}
                  </div>

                  <!-- Item Content & Badge -->
                  <div class="flex-1 min-w-0 flex items-center justify-between gap-2">
                    <span class="leading-snug break-words {item.status === 'completed' ? 'line-through text-slate-400' : item.status === 'in_progress' ? 'font-medium text-slate-100' : 'text-slate-300'}">
                      {idx + 1}. {item.content}
                    </span>

                    <span class="shrink-0 text-[10px] uppercase font-mono px-1.5 py-0.5 rounded font-semibold {item.status === 'completed' ? 'bg-emerald-900/40 text-emerald-300 border border-emerald-800/50' : item.status === 'in_progress' ? 'bg-indigo-900/60 text-indigo-300 border border-indigo-700 animate-pulse' : 'bg-slate-800 text-slate-400 border border-slate-700/50'}">
                      {item.status === 'completed' ? 'Готово' : item.status === 'in_progress' ? 'В процессе' : 'Ожидает'}
                    </span>
                  </div>
                </div>
              {/each}
            </div>
          {/if}
        </div>
      {/if}
    </div>

    <!-- Tabs Navigation: Unified Dialog & Tools + Expert -->
    <div class="flex border-b border-slate-800 text-xs font-medium">
      <button
        on:click={() => activeTab = 'dialog'}
        class="flex-1 py-2.5 px-3 text-center border-b-2 transition flex items-center justify-center gap-1.5 cursor-pointer {activeTab === 'dialog' ? 'border-indigo-500 text-indigo-400 bg-indigo-950/20' : 'border-transparent text-slate-400 hover:text-slate-200'}"
      >
        <span>Диалог и Шаги</span>
        <span class="text-[10px] px-1.5 py-0.2 rounded-full bg-slate-800 text-slate-400">
          {currentNode.dialog.length}
        </span>
      </button>

      <button
        on:click={() => activeTab = 'human'}
        class="flex-1 py-2.5 px-3 text-center border-b-2 transition flex items-center justify-center gap-1.5 cursor-pointer {activeTab === 'human' ? 'border-amber-500 text-amber-400 bg-amber-950/20' : 'border-transparent text-slate-400 hover:text-slate-200'}"
      >
        <span>Expert / Решение</span>
        {#if currentNode.status === 'waiting_human'}
          <span class="w-2 h-2 rounded-full bg-amber-400 animate-ping"></span>
        {/if}
      </button>
    </div>

    <!-- Tab Contents -->
    <div class="flex-1 overflow-y-auto p-4 space-y-3 font-sans">
      {#if activeTab === 'dialog'}
        <div class="space-y-3">
          {#each currentNode.dialog as msg, idx}
            {@const stepLabel = getStepLabel(msg, idx, currentNode.dialog)}

            {#if msg.role === 'system'}
              {#if msg.text.startsWith('🛑') || msg.text.startsWith('⚠️') || msg.text.startsWith('🔁')}
                <!-- Alert notification message for anti-loop, token limit, and LLM retry -->
                <div class="rounded-xl p-3 text-xs leading-relaxed {msg.text.startsWith('🛑') ? 'bg-rose-950/40 border border-rose-800/60 text-rose-200' : 'bg-amber-950/30 border border-amber-800/50 text-amber-200'}">
                  <div class="font-bold text-[10px] uppercase tracking-wider mb-1 flex items-center justify-between {msg.text.startsWith('🛑') ? 'text-rose-400' : 'text-amber-400'}">
                    <span class="flex items-center gap-1.5">
                      <span>{msg.text.startsWith('🛑') ? '🛑' : msg.text.startsWith('🔁') ? '🔁' : '⚠️'}</span>
                      <span>Системное оповещение</span>
                      {#if stepLabel}
                        <span class="px-1.5 py-0.2 rounded bg-slate-900/90 font-mono text-[9px] border border-slate-700/60">{stepLabel}</span>
                      {/if}
                    </span>
                    <span class="text-slate-500 font-mono text-[9px]">{new Date(msg.timestamp).toLocaleTimeString()}</span>
                  </div>
                  <div class="whitespace-pre-wrap select-text font-medium">{msg.text}</div>
                </div>
              {:else}
                <!-- System Prompt (Collapsible) -->
                <details class="group rounded-xl border border-slate-800 bg-slate-950/60 p-3 text-xs leading-relaxed transition">
                  <summary class="cursor-pointer list-none flex items-center justify-between text-[10px] uppercase font-bold tracking-wider text-slate-400 hover:text-slate-200">
                    <span class="flex items-center gap-1.5">
                      <span>⚙️</span>
                      <span>Системный промпт (System)</span>
                    </span>
                    <div class="flex items-center gap-2">
                      <span class="text-slate-600 font-mono text-[9px]">{new Date(msg.timestamp).toLocaleTimeString()}</span>
                      <span class="text-slate-500 group-open:rotate-180 transition-transform text-[11px]">▼</span>
                    </div>
                  </summary>
                  <div class="mt-2.5 pt-2 border-t border-slate-800/80 whitespace-pre-wrap select-text font-mono text-[11px] text-slate-300 max-h-60 overflow-y-auto bg-slate-900/40 p-2.5 rounded-lg">
                    {msg.text}
                  </div>
                </details>
              {/if}

            {:else if msg.role === 'user'}
              <!-- User Prompt -->
              <div class="rounded-xl p-3 text-xs leading-relaxed bg-indigo-950/40 border border-indigo-800/40 ml-2">
                <div class="flex items-center justify-between mb-1.5 text-[10px] uppercase font-bold tracking-wider text-indigo-400">
                  <span class="flex items-center gap-1.5">
                    <span>👤</span>
                    <span>Пользователь (User Prompt)</span>
                    {#if stepLabel}
                      <span class="px-1.5 py-0.2 rounded bg-indigo-900/80 font-mono text-[9px] text-indigo-200 border border-indigo-700/60">{stepLabel}</span>
                    {/if}
                  </span>
                  <span class="text-slate-500 font-mono text-[9px]">{new Date(msg.timestamp).toLocaleTimeString()}</span>
                </div>
                <div class="whitespace-pre-wrap select-text text-slate-100">{msg.text}</div>
              </div>

            {:else if msg.role === 'thinking'}
              <!-- Model Reasoning / Thinking -->
              <details open class="group rounded-xl border border-purple-800/50 bg-purple-950/20 p-3 text-xs leading-relaxed transition mr-2">
                <summary class="cursor-pointer list-none flex items-center justify-between text-[10px] uppercase font-bold tracking-wider text-purple-300 hover:text-purple-200">
                  <span class="flex items-center gap-1.5">
                    <span>🧠</span>
                    <span>Рассуждения (Thinking)</span>
                    {#if stepLabel}
                      <span class="px-1.5 py-0.2 rounded bg-purple-900/80 font-mono text-[9px] text-purple-200 font-bold border border-purple-700/60 shadow-sm">{stepLabel}</span>
                    {/if}
                  </span>
                  <div class="flex items-center gap-2">
                    <span class="text-slate-500 font-mono text-[9px]">{new Date(msg.timestamp).toLocaleTimeString()}</span>
                    <span class="text-purple-400 group-open:rotate-180 transition-transform text-[11px]">▼</span>
                  </div>
                </summary>
                <div class="mt-2.5 pt-2 border-t border-purple-800/40 whitespace-pre-wrap select-text font-mono text-[11px] text-purple-200/90 italic max-h-56 overflow-y-auto bg-purple-950/30 p-2.5 rounded-lg border border-purple-900/40">
                  {msg.text}
                </div>
              </details>

            {:else if msg.role === 'tool'}
              <!-- Dedicated Rich Skill Card (Auto-open if running or recent) -->
              {@const isRunning = msg.status === 'running'}
              {@const isOpen = isRunning || idx >= currentNode.dialog.length - 2}
              <details open={isOpen} class="group rounded-xl border border-slate-800 bg-slate-950/80 p-3 text-xs select-text transition shadow-sm">
                <!-- Summary Title Bar -->
                <summary class="cursor-pointer list-none flex items-center justify-between">
                  <div class="flex items-center gap-2 flex-1 min-w-0 mr-2">
                    {#if stepLabel}
                      <span class="px-1.5 py-0.2 rounded bg-violet-950/90 font-mono text-[9px] text-violet-300 font-bold border border-violet-700/60 shrink-0">{stepLabel}</span>
                    {/if}
                    <span class="text-sm shrink-0">{getSkillIcon(msg.name)}</span>
                    <span class="font-mono font-bold text-violet-400 truncate">
                      {msg.name || 'tool'}
                    </span>
                    {#if msg.args?.path}
                      <span class="text-[11px] text-slate-400 font-mono truncate max-w-[200px]">
                        {msg.args.path}
                      </span>
                    {:else if msg.args?.command || msg.args?.cmd}
                      <span class="text-[11px] text-slate-400 font-mono truncate max-w-[200px]">
                        $ {getCommandString(msg.args)}
                      </span>
                    {:else if msg.args?.agent_role}
                      <span class="text-[11px] text-indigo-300 font-medium truncate max-w-[160px]">
                        → {msg.args.agent_role}
                      </span>
                    {/if}
                  </div>

                  <div class="flex items-center gap-2 shrink-0">
                    {#if isRunning}
                      <span class="flex items-center gap-1 text-[10px] text-amber-400 font-mono">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-ping"></span>
                        <span>Выполняется...</span>
                      </span>
                    {:else if msg.durationMs}
                      <span class="text-[10px] font-mono text-slate-500">
                        {msg.durationMs}ms
                      </span>
                    {/if}
                    <span class="text-[10px] font-mono px-1.5 py-0.5 rounded {msg.status === 'ok' ? 'bg-emerald-950 text-emerald-400 border border-emerald-800/50' : isRunning ? 'bg-amber-950 text-amber-400 border border-amber-800/50' : 'bg-rose-950 text-rose-400 border border-rose-800/50'}">
                      {isRunning ? 'RUNNING' : (msg.status || 'OK').toUpperCase()}
                    </span>
                    <span class="text-slate-500 group-open:rotate-180 transition-transform text-[11px]">▼</span>
                  </div>
                </summary>

                <!-- Dedicated Body Views by Tool Type -->
                <div class="mt-3 pt-2.5 border-t border-slate-800/80 space-y-2.5">
                  {#if msg.name === 'write_file'}
                    <!-- 1. File Writing View: Collapsible Code Content -->
                    <div class="space-y-1.5">
                      <details open class="group/content rounded-lg border border-slate-800 bg-slate-900/60 overflow-hidden">
                        <summary class="cursor-pointer list-none px-2.5 py-1.5 bg-slate-900/90 hover:bg-slate-900 border-b border-slate-800/80 flex items-center justify-between text-[11px] font-mono select-none">
                          <div class="flex items-center gap-2 truncate">
                            <span class="text-emerald-400 font-bold">📄 {msg.args?.path || 'file'}</span>
                            <span class="text-slate-500 text-[10px]">({msg.args?.content ? msg.args.content.length : 0} симв.)</span>
                          </div>
                          <span class="text-slate-500 group-open/content:rotate-180 transition-transform text-[10px]">▼</span>
                        </summary>
                        {#if msg.args?.content}
                          <div class="p-2.5 bg-slate-950 font-mono text-[11px] text-emerald-300 max-h-64 overflow-y-auto whitespace-pre-wrap select-text leading-relaxed">
                            {msg.args.content}
                          </div>
                        {/if}
                      </details>
                      <div class="text-[11px] font-mono font-medium flex items-center gap-1.5 {msg.error ? 'text-rose-400' : 'text-emerald-400'} px-1">
                        <span>{msg.error ? '❌' : '✓'}</span>
                        <span>{msg.error || msg.output || 'Файл успешно сохранен'}</span>
                      </div>
                    </div>

                  {:else if msg.name === 'host_exec' || msg.name === 'docker_exec'}
                    <!-- 2. Terminal View for Shell / Docker Commands -->
                    {@const term = getCleanTerminalOutput(msg.output || msg.text || '')}
                    <details open class="group/term rounded-lg overflow-hidden border border-slate-800 bg-black font-mono shadow-md">
                      <summary class="cursor-pointer list-none bg-slate-900/90 px-3 py-1.5 border-b border-slate-800 flex items-center justify-between text-[10px] select-none hover:bg-slate-900">
                        <div class="flex items-center gap-2 truncate">
                          <div class="flex items-center gap-1 shrink-0">
                            <span class="w-2.5 h-2.5 rounded-full bg-rose-500/80"></span>
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500/80"></span>
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500/80"></span>
                          </div>
                          <span class="text-slate-400 font-semibold shrink-0">{msg.name === 'docker_exec' ? '🐳 docker: ' + (msg.args?.container || 'default') : '💻 host: /workspace'}</span>
                          <span class="text-slate-500 truncate max-w-[180px] font-mono">$ {getCommandString(msg.args)}</span>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                          {#if term.exitCode !== undefined}
                            <span class="px-1.5 py-0.2 rounded font-mono text-[9px] {term.exitCode === 0 ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-rose-950 text-rose-400 border border-rose-800'}">
                              Exit: {term.exitCode}
                            </span>
                          {/if}
                          <span class="text-slate-500 group-open/term:rotate-180 transition-transform text-[10px]">▼</span>
                        </div>
                      </summary>

                      <div class="p-3 text-[11px] select-text">
                        <div class="flex items-center gap-2 text-indigo-400 font-bold mb-1.5">
                          <span>$</span>
                          <span class="text-slate-100">{getCommandString(msg.args)}</span>
                        </div>

                        {#if isRunning}
                          <div class="text-amber-400 font-mono text-[11px] py-2 flex items-center gap-2 animate-pulse">
                            <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                            <span>Выполнение команды в терминале...</span>
                          </div>
                        {:else}
                          {#if term.stdout}
                            <pre class="text-slate-200 whitespace-pre-wrap max-h-64 overflow-y-auto leading-relaxed">{term.stdout}</pre>
                          {/if}
                          {#if term.stderr}
                            <pre class="text-rose-400 whitespace-pre-wrap max-h-48 overflow-y-auto leading-relaxed mt-2 pt-2 border-t border-rose-950/40">{term.stderr}</pre>
                          {/if}
                          {#if !term.stdout && !term.stderr}
                            <div class="text-slate-500 italic text-[10px]">Команда выполнена (без вывода в консоль).</div>
                          {/if}
                        {/if}
                      </div>
                    </details>

                  {:else if msg.name === 'call_sub_agent'}
                    <!-- 3. Sub-Agent Delegation Card -->
                    <div class="space-y-2">
                      <div class="flex items-center gap-2 bg-indigo-950/50 p-2 rounded-lg border border-indigo-800/60">
                        <span class="text-base">🤖</span>
                        <div class="truncate">
                          <span class="text-[10px] text-indigo-400 uppercase font-bold tracking-wider block">Делегирование саб-агенту:</span>
                          <span class="text-xs font-semibold text-slate-100">{msg.args?.agent_role || msg.args?.role || 'Sub-Agent'}</span>
                        </div>
                      </div>

                      {#if msg.args?.task || msg.args?.task_instructions}
                        <div class="space-y-1">
                          <span class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">📝 Текст задачи (Task Prompt):</span>
                          <div class="p-2.5 rounded-lg bg-indigo-950/30 border border-indigo-900/40 text-indigo-100 text-xs whitespace-pre-wrap leading-relaxed select-text font-sans">
                            {msg.args.task || msg.args.task_instructions}
                          </div>
                        </div>
                      {/if}

                      <div class="space-y-1">
                        <span class="text-[9px] uppercase font-bold text-slate-400 tracking-wider">🏁 Результат работы саб-агента:</span>
                        {#if isRunning}
                          <div class="p-2 text-amber-400 animate-pulse text-xs">Саб-агент выполняет подзадачу...</div>
                        {:else}
                          <div class="p-2.5 rounded-lg bg-slate-900/90 border border-slate-800 text-slate-200 text-xs whitespace-pre-wrap leading-relaxed select-text max-h-72 overflow-y-auto font-sans">
                            {msg.output || msg.text || msg.error || 'OK'}
                          </div>
                        {/if}
                      </div>
                    </div>

                  {:else if msg.name === 'list_dir'}
                    <!-- 4. Directory Listing File Tree / Grid (Collapsible) -->
                    {@const dirData = parseListDirOutput(msg.output || msg.text || '')}
                    <div class="space-y-1.5">
                      <details open class="group/content rounded-lg border border-slate-800 bg-slate-900/60 overflow-hidden">
                        <summary class="cursor-pointer list-none px-2.5 py-1.5 bg-slate-900/90 hover:bg-slate-900 border-b border-slate-800/80 flex items-center justify-between text-[11px] font-mono select-none">
                          <div class="flex items-center gap-2 truncate">
                            <span class="text-cyan-400 font-bold">📁 {msg.args?.path || '.'}</span>
                            <span class="text-slate-500 text-[10px]">({dirData.items.length} элементов)</span>
                          </div>
                          <span class="text-slate-500 group-open/content:rotate-180 transition-transform text-[10px]">▼</span>
                        </summary>

                        {#if isRunning}
                          <div class="text-amber-400 animate-pulse text-xs p-3">Чтение файловой структуры...</div>
                        {:else if dirData.items.length > 0}
                          <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 max-h-64 overflow-y-auto p-2 bg-slate-950">
                            {#each dirData.items as item}
                              <div class="flex items-center justify-between p-1.5 rounded bg-slate-900/80 border border-slate-800/80 text-xs">
                                <div class="flex items-center gap-1.5 truncate">
                                  <span>{item.type === 'dir' ? '📁' : '📄'}</span>
                                  <span class="{item.type === 'dir' ? 'text-cyan-300 font-semibold' : 'text-slate-200 font-mono'} truncate">{item.name}</span>
                                </div>
                                {#if item.size}
                                  <span class="text-[10px] text-slate-400 font-mono shrink-0 ml-1">{item.size}</span>
                                {/if}
                              </div>
                            {/each}
                          </div>
                        {:else}
                          <pre class="bg-slate-950 p-2 font-mono text-[11px] text-slate-400 max-h-48 overflow-y-auto">{msg.output || msg.text || 'Каталог пуст.'}</pre>
                        {/if}
                      </details>
                    </div>

                  {:else if msg.name === 'read_file'}
                    <!-- 5. Formatted File Reading with Real Line Numbers (Collapsible) -->
                    {@const readData = parseReadFileLines(msg.output || msg.text || '')}
                    <div class="space-y-1.5">
                      <details open class="group/content rounded-lg border border-slate-800 bg-slate-900/60 overflow-hidden">
                        <summary class="cursor-pointer list-none px-2.5 py-1.5 bg-slate-900/90 hover:bg-slate-900 border-b border-slate-800/80 flex items-center justify-between text-[11px] font-mono select-none">
                          <div class="flex items-center gap-2 truncate">
                            <span class="text-cyan-400 font-bold">📄 {msg.args?.path || 'file'}</span>
                            {#if msg.args?.start_line}
                              <span class="text-slate-400 text-[10px]">строки {msg.args.start_line}–{msg.args.end_line || ''}</span>
                            {/if}
                            <span class="text-slate-500 text-[10px]">({readData.lines.length} строк)</span>
                          </div>
                          <span class="text-slate-500 group-open/content:rotate-180 transition-transform text-[10px]">▼</span>
                        </summary>

                        {#if isRunning}
                          <div class="text-amber-400 animate-pulse text-xs p-3">Чтение файла...</div>
                        {:else if readData.lines.length > 0}
                          <div class="bg-slate-950 font-mono text-[11px] max-h-72 overflow-y-auto p-2 select-text leading-relaxed">
                            {#each readData.lines as line}
                              <div class="flex items-start gap-2.5 hover:bg-slate-900/50 rounded px-1">
                                <span class="w-9 text-right text-slate-500 select-none shrink-0 font-mono text-[10px]">{line.num}</span>
                                <span class="text-slate-200 whitespace-pre font-mono flex-1">{line.code}</span>
                              </div>
                            {/each}
                            {#if readData.footer}
                              <div class="mt-2 pt-1 border-t border-slate-800 text-[10px] text-amber-400 font-mono">{readData.footer}</div>
                            {/if}
                          </div>
                        {:else}
                          <pre class="bg-slate-950 p-2 font-mono text-[11px] text-slate-400 max-h-48 overflow-y-auto">{msg.output || msg.text || 'Файл пуст.'}</pre>
                        {/if}
                      </details>
                    </div>

                  {:else if msg.name === 'todo_write'}
                    <!-- 6. Formatted TODO Checklist View -->
                    {@const todoList = getTodoItemsFromMessage(msg)}
                    <div class="space-y-1.5">
                      <span class="text-[10px] font-bold text-indigo-400 uppercase tracking-wider block">📋 Список этапов (TODO Checklist):</span>
                      {#if todoList.length > 0}
                        <div class="space-y-1 bg-slate-900/50 p-2 rounded-lg border border-slate-800">
                          {#each todoList as item}
                            <div class="flex items-center gap-2 p-1.5 rounded bg-slate-950/80 border border-slate-800/60 text-xs">
                              <span class="{item.status === 'completed' ? 'text-emerald-400' : item.status === 'in_progress' ? 'text-indigo-400 font-bold animate-pulse' : 'text-slate-500'} shrink-0 text-sm">
                                {item.status === 'completed' ? '✓' : item.status === 'in_progress' ? '►' : '○'}
                              </span>
                              <span class="{item.status === 'completed' ? 'line-through text-slate-400' : item.status === 'in_progress' ? 'text-indigo-200 font-semibold' : 'text-slate-300'} flex-1 leading-snug">
                                {item.content}
                              </span>
                              <span class="text-[9px] uppercase font-mono px-1.5 py-0.5 rounded shrink-0 {item.status === 'completed' ? 'bg-emerald-950/80 text-emerald-400 border border-emerald-800/50' : item.status === 'in_progress' ? 'bg-indigo-950/80 text-indigo-300 border border-indigo-700/60' : 'bg-slate-800 text-slate-400'}">
                                {item.status}
                              </span>
                            </div>
                          {/each}
                        </div>
                      {/if}
                      {#if msg.args?.expected_outcome}
                        <div class="p-2 rounded bg-indigo-950/30 border border-indigo-800/40 text-[11px] text-indigo-200">
                          <span class="font-bold">Цель:</span> {msg.args.expected_outcome}
                        </div>
                      {/if}
                    </div>

                  {:else}
                    <!-- 7. Fallback for other skills -->
                    <div>
                      <span class="text-slate-500 font-semibold uppercase text-[9px] block">{msg.error ? 'Ошибка (Error)' : 'Результат (Output)'}:</span>
                      <pre class="p-2 rounded border mt-1 font-mono text-[11px] max-h-48 overflow-y-auto {msg.error ? 'bg-rose-950/40 border-rose-800 text-rose-300' : 'bg-slate-900/80 border-slate-800 text-slate-300'}">{msg.error || msg.output || msg.text || 'OK'}</pre>
                    </div>
                  {/if}

                  <!-- 8. Collapsible Parameters Spoiler for ALL tools -->
                  {#if msg.args && Object.keys(msg.args).length > 0}
                    <details class="mt-2 pt-1 border-t border-slate-800/60 text-[10px] text-slate-500">
                      <summary class="cursor-pointer hover:text-slate-300 font-mono text-[9px] uppercase tracking-wider py-0.5 select-none">
                        ▸ Исходные параметры вызова (JSON Arguments)
                      </summary>
                      <pre class="bg-slate-950 p-2 rounded border border-slate-800/80 mt-1 font-mono text-[10px] text-slate-400 overflow-x-auto leading-relaxed">{JSON.stringify(msg.args, null, 2)}</pre>
                    </details>
                  {/if}
                </div>
              </details>

            {:else if msg.role === 'assistant' && msg.text && msg.text.trim() !== ''}
              <!-- Assistant Step / Output -->
              <div class="rounded-xl p-3 text-xs leading-relaxed bg-slate-800/70 border border-slate-700/60 mr-2 text-slate-200">
                <div class="flex items-center justify-between mb-1.5 text-[10px] uppercase font-bold tracking-wider text-cyan-400">
                  <span class="flex items-center gap-1.5">
                    <span>🤖</span>
                    <span>Ассистент</span>
                    {#if stepLabel}
                      <span class="px-1.5 py-0.2 rounded bg-cyan-950 font-mono text-[9px] text-cyan-300 font-bold border border-cyan-700/60 shadow-sm">{stepLabel}</span>
                    {/if}
                  </span>
                  <span class="text-slate-500 font-mono text-[9px]">{new Date(msg.timestamp).toLocaleTimeString()}</span>
                </div>
                <div class="whitespace-pre-wrap select-text">{msg.text}</div>
              </div>
            {/if}
          {/each}

          {#if currentNode.outputResult && (!currentNode.dialog.some(m => m.text === currentNode.outputResult))}
            <div class="rounded-xl p-3 text-xs leading-relaxed {currentNode.status === 'failed' ? 'bg-rose-950/40 border border-rose-800 text-rose-200' : 'bg-emerald-950/30 border border-emerald-800/50 text-slate-200'}">
              <div class="flex items-center justify-between mb-1 text-[10px] uppercase font-bold tracking-wider {currentNode.status === 'failed' ? 'text-rose-400' : 'text-emerald-400'}">
                <span>{currentNode.status === 'failed' ? '❌ Ошибка выполнения / Error' : '✓ Финальный результат / Final Result'}</span>
              </div>
              <div class="whitespace-pre-wrap select-text font-sans">{currentNode.outputResult}</div>
            </div>
          {/if}
        </div>

      {:else if activeTab === 'human'}
        <div class="space-y-4">
          {#if currentNode.status === 'waiting_human' && currentNode.humanPrompt}
            <div class="p-4 rounded-xl bg-amber-950/40 border border-amber-600/70 text-amber-200">
              <div class="flex items-center gap-2 font-bold text-amber-400 text-xs uppercase mb-2">
                <span>⚠️ Operator Decision Required</span>
              </div>
              <p class="text-xs leading-relaxed select-text font-medium text-amber-100">
                {currentNode.humanPrompt.question}
              </p>

              {#if currentNode.humanPrompt.options && currentNode.humanPrompt.options.length > 0}
                <div class="mt-3 flex flex-wrap gap-2">
                  {#each currentNode.humanPrompt.options as opt}
                    <button
                      on:click={() => submitHumanAnswer(opt)}
                      class="px-2.5 py-1 rounded bg-amber-500/20 hover:bg-amber-500/30 active:bg-amber-500/40 border border-amber-500/50 text-amber-200 text-xs font-medium transition cursor-pointer"
                    >
                      {opt}
                    </button>
                  {/each}
                </div>
              {/if}
            </div>

            <div class="space-y-2">
              <label for="human-answer-input" class="text-xs text-slate-400 font-medium">Custom Answer / Guidance:</label>
              <textarea
                id="human-answer-input"
                bind:value={humanAnswerText}
                placeholder="Type instructions or confirmation for the agent..."
                rows="4"
                class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-xs text-slate-100 placeholder-slate-600 focus:outline-none focus:border-indigo-500 resize-y min-h-[90px] max-h-[400px] leading-relaxed font-sans"
              ></textarea>
              <button
                on:click={() => submitHumanAnswer(humanAnswerText)}
                disabled={!humanAnswerText.trim()}
                class="w-full py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 disabled:opacity-50 text-white font-medium text-xs transition flex items-center justify-center gap-2 cursor-pointer"
              >
                <span>Send Answer to Agent</span>
              </button>
            </div>
          {:else}
            <div class="text-center py-8 text-xs text-slate-500">
              Agent is not currently waiting for human intervention.
            </div>
          {/if}
        </div>
      {/if}
    </div>

    <!-- Sticky Bottom Bar: Send Message to Manager for Refinement -->
    <div class="p-3 border-t border-slate-800 bg-slate-950/90 space-y-2 shrink-0">
      <div class="flex items-center justify-between">
        <span class="text-[11px] font-semibold text-slate-300 flex items-center gap-1.5">
          <span>💬</span>
          <span>Сообщение менеджеру (доработка задачи):</span>
        </span>
        {#if isSendingFollowup}
          <span class="text-[10px] text-indigo-400 font-mono animate-pulse">Отправка...</span>
        {/if}
      </div>

      <div class="flex gap-2">
        <textarea
          bind:value={followupText}
          placeholder="Напишите, что нужно доделать или уточнить по задаче..."
          rows="2"
          disabled={isSendingFollowup || $sessionStore.status === 'running'}
          class="flex-1 bg-slate-900 border border-slate-800 rounded-lg p-2.5 text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-indigo-500 resize-y min-h-[70px] max-h-[350px] leading-relaxed font-sans disabled:opacity-50"
        ></textarea>
        <button
          on:click={handleSendFollowup}
          disabled={isSendingFollowup || !followupText.trim() || $sessionStore.status === 'running'}
          class="px-3.5 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 disabled:opacity-50 text-white font-medium text-xs transition flex flex-col items-center justify-center gap-0.5 cursor-pointer shrink-0 shadow"
          title="Отправить указания менеджеру для продолжения работы"
        >
          <span>🚀</span>
          <span class="text-[10px] font-bold">Отправить</span>
        </button>
      </div>
      {#if $sessionStore.status === 'running'}
        <p class="text-[10px] text-amber-400/90">
          ⏳ Сессия сейчас выполняется. Дождитесь завершения текущего этапа, чтобы отправить новые указания.
        </p>
      {:else}
        <p class="text-[10px] text-slate-500">
          Менеджер получит ваше указание с контекстом предыдущей работы и выполнит доработку через саб-агентов.
        </p>
      {/if}
    </div>
  {/if}
</aside>
