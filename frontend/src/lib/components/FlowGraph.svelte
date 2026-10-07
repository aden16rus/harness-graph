<script lang="ts">
  import { writable } from 'svelte/store';
  import {
    SvelteFlow,
    Background,
    Controls,
    MiniMap,
    type Node,
    type Edge,
  } from '@xyflow/svelte';
  import '@xyflow/svelte/dist/style.css';

  import CustomNode from './CustomNode.svelte';
  import { nodesStore, selectedNodeId } from '../stores/websocket';
  import type { AgentNodeData } from '../types';

  const nodeTypes = {
    custom: CustomNode,
  };

  const nodes = writable<Node[]>([]);
  const edges = writable<Edge[]>([]);
  const viewport = writable({ x: 0, y: 0, zoom: 1 });

  let viewMode: 'flow' | 'tree' = 'flow';

  $: rawNodeList = Array.from($nodesStore.values());

  // Recompute graph positions when nodesStore updates
  $: {
    const rawNodes = rawNodeList;
    const newNodes: Node[] = [];
    const newEdges: Edge[] = [];

    // Group nodes by depth for hierarchical tree layout
    const depthGroups: Record<number, typeof rawNodes> = {};
    for (const n of rawNodes) {
      if (!depthGroups[n.depth]) depthGroups[n.depth] = [];
      depthGroups[n.depth].push(n);
    }

    const NODE_WIDTH = 280;
    const HORIZONTAL_GAP = 60;
    const VERTICAL_GAP = 200;

    for (const [depthStr, list] of Object.entries(depthGroups)) {
      const depth = Number(depthStr);
      const totalWidth = list.length * NODE_WIDTH + (list.length - 1) * HORIZONTAL_GAP;
      const startX = 600 - totalWidth / 2;

      list.forEach((item, index) => {
        const x = startX + index * (NODE_WIDTH + HORIZONTAL_GAP);
        const y = 80 + depth * VERTICAL_GAP;

        newNodes.push({
          id: item.id,
          type: 'custom',
          position: { x, y },
          data: { ...item },
        });

        if (item.parentId) {
          newEdges.push({
            id: `edge-${item.parentId}-${item.id}`,
            source: item.parentId,
            target: item.id,
            animated: item.status === 'active' || item.status === 'calling_tool',
            style: 'stroke: #6366f1; stroke-width: 2px;',
          });
        }
      });
    }

    nodes.set(newNodes);
    edges.set(newEdges);
  }

  function handleNodeClick(event: any) {
    if (event?.detail?.node?.id) {
      selectedNodeId.set(event.detail.node.id);
    }
  }

  function selectNode(id: string) {
    selectedNodeId.set(id);
  }
</script>

<div class="w-full h-full relative bg-slate-950 overflow-hidden select-none">
  <!-- View Mode Switcher -->
  <div class="absolute top-3 left-3 z-20 flex items-center bg-slate-900/90 border border-slate-800 rounded-lg p-0.5 text-xs shadow-lg backdrop-blur">
    <button
      on:click={() => viewMode = 'flow'}
      class="px-2.5 py-1 rounded-md transition font-medium {viewMode === 'flow' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'}"
    >
      Flow Graph
    </button>
    <button
      on:click={() => viewMode = 'tree'}
      class="px-2.5 py-1 rounded-md transition font-medium {viewMode === 'tree' ? 'bg-indigo-600 text-white shadow' : 'text-slate-400 hover:text-slate-200'}"
    >
      Tree View ({rawNodeList.length})
    </button>
  </div>

  {#if rawNodeList.length === 0}
    <div class="absolute inset-0 flex flex-col items-center justify-center text-slate-500 z-10 select-none">
      <div class="w-16 h-16 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-center mb-4 text-2xl shadow-xl">
        🕸️
      </div>
      <p class="font-medium text-slate-400">Waiting for task execution...</p>
      <p class="text-xs text-slate-600 mt-1">Start a session with "Run Task" to visualize multi-agent graph in real-time</p>
    </div>
  {/if}

  {#if viewMode === 'flow'}
    <SvelteFlow
      {nodes}
      {edges}
      {nodeTypes}
      {viewport}
      fitView
      on:nodeclick={handleNodeClick}
      class="bg-slate-950"
    >
      <Background color="#334155" gap={24} size={1.5} />
      <Controls class="!bg-slate-900 !border-slate-800 !text-slate-300 fill-slate-300" />
      <MiniMap
        nodeColor="#6366f1"
        class="!bg-slate-900/90 !border-slate-800 !rounded-lg overflow-hidden shadow-lg"
      />
    </SvelteFlow>
  {:else}
    <!-- Structured Tree View -->
    <div class="w-full h-full overflow-y-auto p-16 space-y-4 max-w-4xl mx-auto">
      {#each rawNodeList as n}
        <button
          type="button"
          on:click={() => selectNode(n.id)}
          class="w-full text-left p-4 rounded-xl border transition flex items-center justify-between cursor-pointer {$selectedNodeId === n.id ? 'border-indigo-500 bg-indigo-950/30' : 'border-slate-800 bg-slate-900/70 hover:border-slate-700'}"
          style="margin-left: {n.depth * 32}px;"
        >
          <div class="flex items-center gap-3">
            <div class="w-3 h-3 rounded-full {n.status === 'active' ? 'bg-cyan-400 animate-pulse' : n.status === 'calling_tool' ? 'bg-violet-400 animate-ping' : n.status === 'waiting_human' ? 'bg-amber-400 animate-bounce' : n.status === 'completed' ? 'bg-emerald-400' : 'bg-slate-500'}"></div>
            <div>
              <div class="font-bold text-sm text-slate-100 flex items-center gap-2">
                <span>{n.agentName}</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded uppercase font-semibold bg-slate-800 text-slate-400 border border-slate-700">{n.role}</span>
              </div>
              <p class="text-xs text-slate-400 mt-1 line-clamp-1">{n.inputPrompt}</p>
            </div>
          </div>

          <div class="flex items-center gap-4 text-xs font-mono text-slate-400">
            {#if n.activeTool}
              <span class="px-2 py-0.5 rounded bg-violet-950 text-violet-300 border border-violet-800 text-[11px]">
                {n.activeTool}
              </span>
            {/if}
            <span>{((n.promptTokens || 0) + (n.completionTokens || 0)).toLocaleString()} tok</span>
            <span class="text-[10px] uppercase font-semibold text-slate-300 bg-slate-800 px-2 py-0.5 rounded">
              {n.status}
            </span>
          </div>
        </button>
      {/each}
    </div>
  {/if}
</div>
