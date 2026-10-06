<script lang="ts">
  import { nodesStore, selectedNodeId, wsManager } from '../stores/websocket';
  import type { AgentNodeData } from '../types';

  let activeTab: 'dialog' | 'human' = 'dialog';
  let humanAnswerText = '';

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

  function getSkillIcon(name: string = ''): string {
    const n = (name || '').toLowerCase();
    if (n.includes('read_file')) return '📄';
    if (n.includes('write_file')) return '✏️';
    if (n.includes('list_dir')) return '📁';
    if (n.includes('host_exec')) return '💻';
    if (n.includes('docker_exec')) return '🐳';
    if (n.includes('call_sub_agent')) return '🤖';
    if (n.includes('ask_human_expert')) return '👤';
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
</script>

<aside class="w-full border-l border-slate-800 bg-slate-900/95 flex flex-col h-full shadow-2xl backdrop-blur select-none z-10 text-slate-100">
  {#if !currentNode}
    <div class="flex-1 flex flex-col items-center justify-center p-6 text-center text-slate-500">
      <div class="w-12 h-12 rounded-xl bg-slate-800/80 border border-slate-700/60 flex items-center justify-center mb-3 text-lg">
        🔍
      </div>
      <h3 class="font-medium text-slate-400 text-sm">Node Inspector</h3>
      <p class="text-xs text-slate-600 mt-1">Click any node on the graph to inspect isolated context, tool execution traces, or respond to expert queries.</p>
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
            {(currentNode.promptTokens + currentNode.completionTokens).toLocaleString()}
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
    </div>

    <!-- Tabs Navigation: Unified Dialog & Tools + Expert -->
    <div class="flex border-b border-slate-800 text-xs font-medium">
      <button
        on:click={() => activeTab = 'dialog'}
        class="flex-1 py-2.5 px-3 text-center border-b-2 transition flex items-center justify-center gap-1.5 cursor-pointer {activeTab === 'dialog' ? 'border-indigo-500 text-indigo-400 bg-indigo-950/20' : 'border-transparent text-slate-400 hover:text-slate-200'}"
      >
        <span>Диалог и Скилы</span>
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
          {#each currentNode.dialog as msg}
            {#if msg.role === 'system'}
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

            {:else if msg.role === 'user'}
              <!-- User Prompt -->
              <div class="rounded-xl p-3 text-xs leading-relaxed bg-indigo-950/40 border border-indigo-800/40 ml-2">
                <div class="flex items-center justify-between mb-1.5 text-[10px] uppercase font-bold tracking-wider text-indigo-400">
                  <span class="flex items-center gap-1.5">
                    <span>👤</span> <span>Пользователь (User Prompt)</span>
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
                    <span>Рассуждения (Thinking / Reasoning)</span>
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
                    <span class="text-sm">{getSkillIcon(msg.name)}</span>
                    <span class="font-mono font-bold text-violet-400 truncate">
                      {msg.name || 'tool'}
                    </span>
                    {#if msg.args}
                      <span class="text-[11px] text-slate-500 font-mono truncate max-w-[160px] hidden sm:inline">
                        {getArgsPreview(msg.args)}
                      </span>
                    {/if}
                  </div>

                  <div class="flex items-center gap-2">
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
              <!-- Assistant Step / Final Answer -->
              <div class="rounded-xl p-3 text-xs leading-relaxed bg-slate-800/70 border border-slate-700/60 mr-2 text-slate-200">
                <div class="flex items-center justify-between mb-1.5 text-[10px] uppercase font-bold tracking-wider text-cyan-400">
                  <span class="flex items-center gap-1.5">
                    <span>🤖</span> <span>Ассистент (Assistant Response)</span>
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
  {/if}
</aside>
