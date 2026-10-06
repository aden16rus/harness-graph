<script lang="ts">
  import { onMount } from 'svelte';
  import MetricsHeader from './lib/components/MetricsHeader.svelte';
  import FlowGraph from './lib/components/FlowGraph.svelte';
  import InspectorPanel from './lib/components/InspectorPanel.svelte';
  import SettingsModal from './lib/components/SettingsModal.svelte';
  import TaskModal from './lib/components/TaskModal.svelte';
  import TasksHistoryModal from './lib/components/TasksHistoryModal.svelte';
  import { wsManager } from './lib/stores/websocket';

  let isSettingsOpen = false;
  let isTaskModalOpen = false;
  let isHistoryOpen = false;

  let inspectorWidth = 460;
  let isDragging = false;
  const minWidth = 300;

  onMount(() => {
    wsManager.connect('all');
    const saved = localStorage.getItem('inspector_panel_width');
    if (saved) {
      const parsed = parseInt(saved, 10);
      if (!isNaN(parsed) && parsed >= minWidth && parsed <= window.innerWidth - 300) {
        inspectorWidth = parsed;
      }
    }
  });

  function startResize(e: MouseEvent) {
    isDragging = true;
    e.preventDefault();

    function onMouseMove(moveEvent: MouseEvent) {
      if (!isDragging) return;
      const newWidth = window.innerWidth - moveEvent.clientX;
      const maxAllowed = window.innerWidth - 300;
      if (newWidth >= minWidth && newWidth <= maxAllowed) {
        inspectorWidth = newWidth;
      }
    }

    function onMouseUp() {
      isDragging = false;
      localStorage.setItem('inspector_panel_width', inspectorWidth.toString());
      window.removeEventListener('mousemove', onMouseMove);
      window.removeEventListener('mouseup', onMouseUp);
    }

    window.addEventListener('mousemove', onMouseMove);
    window.addEventListener('mouseup', onMouseUp);
  }
</script>

<div class="w-screen h-screen flex flex-col bg-slate-950 font-sans text-slate-100 overflow-hidden {isDragging ? 'select-none cursor-col-resize' : ''}">
  <MetricsHeader
    onOpenSettings={() => isSettingsOpen = true}
    onNewSession={() => isTaskModalOpen = true}
    onOpenTasksHistory={() => isHistoryOpen = true}
  />

  <main class="flex-1 flex overflow-hidden relative">
    <div class="flex-1 h-full relative overflow-hidden" style="min-width: 280px;">
      <FlowGraph />
    </div>

    <!-- Draggable Splitter Handle -->
    <button
      type="button"
      aria-label="Изменить размер панели"
      on:mousedown={startResize}
      class="w-2 hover:w-2.5 transition-all bg-slate-800 hover:bg-indigo-500 active:bg-indigo-600 cursor-col-resize flex items-center justify-center group z-30 select-none shadow-lg border-0 p-0 focus:outline-none"
      title="Потяните для изменения размера графа и диалога"
    >
      <div class="w-0.5 h-8 bg-slate-600 group-hover:bg-white rounded-full transition"></div>
    </button>

    <div style="width: {inspectorWidth}px; flex-shrink: 0;" class="h-full overflow-hidden">
      <InspectorPanel />
    </div>
  </main>

  <SettingsModal
    isOpen={isSettingsOpen}
    onClose={() => isSettingsOpen = false}
  />

  <TaskModal
    isOpen={isTaskModalOpen}
    onClose={() => isTaskModalOpen = false}
  />

  <TasksHistoryModal
    isOpen={isHistoryOpen}
    onClose={() => isHistoryOpen = false}
  />
</div>
