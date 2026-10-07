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
              <!-- Skill Execution Card: Collapsed by Default -->
              <details class="group rounded-xl border border-slate-800 bg-slate-950/80 p-3 text-xs select-text transition">
                <summary class="cursor-pointer list-none flex items-center justify-between">
                  <div class="flex items-center gap-2 flex-1 min-w-0 mr-2">
                    {#if stepLabel}
                      <span class="px-1.5 py-0.2 rounded bg-violet-950/90 font-mono text-[9px] text-violet-300 font-bold border border-violet-700/60 shrink-0">{stepLabel}</span>
                    {/if}
                    <span class="text-sm shrink-0">{getSkillIcon(msg.name)}</span>
                    <span class="font-mono font-bold text-violet-400 truncate">
                      {msg.name || 'tool'}
                    </span>
                    {#if msg.args}
                      <span class="text-[11px] text-slate-500 font-mono truncate max-w-[140px] hidden sm:inline">
                        {getArgsPreview(msg.args)}
                      </span>
                    {/if}
                  </div>

                  <div class="flex items-center gap-2 shrink-0">
                    {#if msg.durationMs}
                      <span class="text-[10px] font-mono text-slate-500">
                        {msg.durationMs}ms
                      </span>
                    {/if}
                    <span class="text-[10px] font-mono px-1.5 py-0.5 rounded {msg.status === 'ok' ? 'bg-emerald-950 text-emerald-400 border border-emerald-800/50' : msg.status === 'running' ? 'bg-amber-950 text-amber-400 animate-pulse border border-amber-800/50' : 'bg-rose-950 text-rose-400 border border-rose-800/50'}">
                      {(msg.status || 'OK').toUpperCase()}
                    </span>
                    <span class="text-slate-500 group-open:rotate-180 transition-transform text-[11px]">▼</span>
                  </div>
                </summary>

                <!-- Expanded Tool Details -->
                <div class="mt-3 pt-2.5 border-t border-slate-800/80 space-y-2">
                  {#if msg.args && Object.keys(msg.args).length > 0}
                    <div>
                      <span class="text-slate-500 font-semibold uppercase text-[9px] block">Параметры вызова (Arguments):</span>
                      <pre class="bg-slate-900 p-2 rounded border border-slate-800/80 mt-1 font-mono text-[11px] text-slate-300 overflow-x-auto">{JSON.stringify(msg.args, null, 2)}</pre>
                    </div>
                  {/if}

                  <div>
                    <span class="text-slate-500 font-semibold uppercase text-[9px] block">{msg.error ? 'Ошибка (Error)' : 'Результат (Output)'}:</span>
                    <pre class="p-2 rounded border mt-1 font-mono text-[11px] max-h-48 overflow-y-auto {msg.error ? 'bg-rose-950/40 border-rose-800 text-rose-300' : 'bg-slate-900/80 border-slate-800 text-slate-300'}">{msg.error || msg.output || msg.text || 'OK'}</pre>
                  </div>
                </div>
              </details>

            {:else if msg.role === 'assistant'}
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
                class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-xs text-slate-100 placeholder-slate-600 focus:outline-none focus:border-indigo-500 resize-none font-mono"
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
          class="flex-1 bg-slate-900 border border-slate-800 rounded-lg p-2 text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-indigo-500 resize-none font-sans disabled:opacity-50"
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
