<script lang="ts">
  import { onMount } from 'svelte';

  export let isOpen = false;
  export let initialPath = '/workspace';
  export let onSelect: (path: string) => void = () => {};
  export let onClose: () => void = () => {};

  let currentPath = '/workspace';
  let parentPath = '';
  let canGoUp = false;
  let rootPath = '/workspace';
  let dirs: Array<{ name: string; path: string; subdir_count: number }> = [];
  let selectedPath = '/workspace';

  let isLoading = false;
  let errorMessage = '';
  let isCreatingFolder = false;
  let newFolderName = '';
  let createStatus = '';

  $: if (isOpen) {
    currentPath = initialPath || '/workspace';
    selectedPath = currentPath;
    loadDirectory(currentPath);
  }

  // Generate clickable breadcrumbs
  $: breadcrumbs = getBreadcrumbs(currentPath, rootPath);

  function getBreadcrumbs(path: string, root: string) {
    const clean = path.replace(/\/+/g, '/').replace(/\/$/, '');
    const parts = clean.split('/').filter(Boolean);
    const crumbs = [];
    let accumulated = '';

    for (let i = 0; i < parts.length; i++) {
      accumulated += '/' + parts[i];
      crumbs.push({
        name: parts[i],
        path: accumulated,
        isRoot: accumulated === root,
        isCurrent: accumulated === clean,
      });
    }
    return crumbs;
  }

  async function loadDirectory(targetPath: string) {
    isLoading = true;
    errorMessage = '';
    createStatus = '';
    try {
      const res = await fetch(`/api/filesystem/dirs?path=${encodeURIComponent(targetPath)}`);
      if (res.ok) {
        const data = await res.json();
        currentPath = data.current_path || targetPath;
        parentPath = data.parent_path || '';
        canGoUp = !!data.can_go_up;
        rootPath = data.root_path || '/workspace';
        dirs = data.dirs || [];
        selectedPath = currentPath;
      } else {
        errorMessage = `Не удалось открыть путь: ${targetPath}`;
      }
    } catch (e: any) {
      errorMessage = e.message || 'Ошибка загрузки директории';
    } finally {
      isLoading = false;
    }
  }

  async function handleCreateFolder() {
    const trimmed = newFolderName.trim();
    if (!trimmed) return;
    createStatus = 'Создание папки...';
    try {
      const res = await fetch('/api/filesystem/mkdir', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          parent: currentPath,
          name: trimmed,
        }),
      });
      if (res.ok) {
        const data = await res.json();
        newFolderName = '';
        isCreatingFolder = false;
        createStatus = '✓ Папка успешно создана!';
        await loadDirectory(currentPath);
        if (data.path) {
          selectedPath = data.path;
        }
        setTimeout(() => createStatus = '', 2000);
      } else {
        createStatus = 'Ошибка при создании папки';
      }
    } catch (e: any) {
      createStatus = 'Ошибка сети';
    }
  }

  function handleConfirm() {
    onSelect(selectedPath || currentPath);
    onClose();
  }

  function handlePathKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter') {
      loadDirectory(currentPath);
    }
  }

  function handleNewFolderKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter') {
      handleCreateFolder();
    }
  }
</script>

{#if isOpen}
  <div class="fixed inset-0 z-60 flex items-center justify-center p-4 bg-slate-950/85 backdrop-blur-sm select-none">
    <div class="w-full max-w-2xl rounded-2xl bg-slate-900 border border-slate-700 shadow-2xl flex flex-col max-h-[85vh] overflow-hidden text-slate-100">
      <!-- Modal Header -->
      <div class="px-5 py-3.5 border-b border-slate-800 flex items-center justify-between bg-slate-950/60">
        <div class="flex items-center gap-2.5">
          <span class="text-xl">📁</span>
          <div>
            <h2 class="font-bold text-sm text-slate-100">Выбор рабочей директории проекта</h2>
            <p class="text-[11px] text-slate-400">Каждый проект должен быть в отдельной папке. Выберите существующую или создайте новую.</p>
          </div>
        </div>
        <button on:click={onClose} class="text-slate-400 hover:text-slate-200 text-lg cursor-pointer">✕</button>
      </div>

      <!-- Navigation & Action Bar -->
      <div class="p-3 border-b border-slate-800 bg-slate-950/40 space-y-2">
        <!-- Breadcrumbs bar -->
        <div class="flex items-center gap-1.5 overflow-x-auto py-1 px-1 text-xs">
          <span class="text-slate-500 text-[11px] mr-1">Путь:</span>
          {#each breadcrumbs as crumb, i}
            <button
              on:click={() => loadDirectory(crumb.path)}
              class="px-2 py-0.5 rounded font-mono text-xs transition cursor-pointer {crumb.isCurrent ? 'bg-indigo-600/30 text-indigo-300 font-bold border border-indigo-500/50' : 'bg-slate-800 hover:bg-slate-700 text-slate-300'}"
            >
              {crumb.name}
            </button>
            {#if i < breadcrumbs.length - 1}
              <span class="text-slate-600 font-bold">/</span>
            {/if}
          {/each}
        </div>

        <div class="flex items-center gap-2">
          <button
            on:click={() => canGoUp && loadDirectory(parentPath)}
            disabled={!canGoUp}
            class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-30 disabled:hover:bg-slate-800 text-slate-200 text-xs font-medium border border-slate-700 flex items-center gap-1 transition cursor-pointer shrink-0"
            title="Перейти на уровень выше"
          >
            <span>⬆</span>
            <span>Вверх</span>
          </button>

          <button
            on:click={() => loadDirectory(rootPath)}
            class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium border border-slate-700 flex items-center gap-1 transition cursor-pointer shrink-0"
            title="Перейти в корень /workspace"
          >
            <span>🏠</span>
            <span>Корень</span>
          </button>

          <button
            on:click={() => loadDirectory(currentPath)}
            class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium border border-slate-700 transition cursor-pointer shrink-0"
            title="Обновить список"
          >
            🔄
          </button>

          <div class="flex-1 min-w-0">
            <input
              type="text"
              bind:value={currentPath}
              on:keydown={handlePathKeydown}
              placeholder="/workspace/project"
              class="w-full bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1.5 text-xs text-slate-200 font-mono focus:outline-none focus:border-indigo-500"
            />
          </div>

          <!-- Create Folder Button -->
          <button
            on:click={() => isCreatingFolder = !isCreatingFolder}
            class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white text-xs font-semibold transition flex items-center gap-1.5 shadow-sm cursor-pointer shrink-0"
            title="Создать новую подпапку"
          >
            <span>➕</span>
            <span>Создать папку</span>
          </button>
        </div>

        <!-- Inline Create Folder Form -->
        {#if isCreatingFolder}
          <div class="p-2.5 rounded-xl bg-slate-900 border border-emerald-600/80 flex items-center gap-2 shadow-lg">
            <span class="text-xs text-emerald-400 font-medium whitespace-nowrap">Имя папки:</span>
            <input
              type="text"
              bind:value={newFolderName}
              on:keydown={handleNewFolderKeydown}
              placeholder="например: backend-service"
              class="flex-1 bg-slate-950 border border-slate-700 rounded px-2.5 py-1 text-xs text-slate-100 font-mono focus:outline-none focus:border-emerald-500"
              autofocus
            />
            <button
              on:click={handleCreateFolder}
              disabled={!newFolderName.trim()}
              class="px-3 py-1 rounded bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 text-white text-xs font-medium transition cursor-pointer shrink-0"
            >
              Создать
            </button>
            <button
              on:click={() => { isCreatingFolder = false; newFolderName = ''; }}
              class="px-2 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-400 text-xs transition cursor-pointer shrink-0"
            >
              Отмена
            </button>
          </div>
        {/if}

        {#if createStatus}
          <div class="text-[11px] font-medium text-emerald-400 px-1">
            {createStatus}
          </div>
        {/if}
      </div>

      <!-- Directory Contents List -->
      <div class="flex-1 overflow-y-auto p-4 space-y-2 text-xs">
        {#if isLoading}
          <div class="py-12 text-center text-slate-400 animate-pulse">
            Загрузка списка директорий...
          </div>
        {:else if errorMessage}
          <div class="p-3 rounded-lg bg-rose-950/60 border border-rose-800 text-rose-300 text-center">
            {errorMessage}
          </div>
        {:else}
          <!-- Current Folder Item (Self) -->
          <div
            on:click={() => selectedPath = currentPath}
            class="p-2.5 rounded-xl border transition flex items-center justify-between cursor-pointer {selectedPath === currentPath ? 'bg-indigo-950/70 border-indigo-500 ring-1 ring-indigo-500/50' : 'bg-slate-950/40 border-slate-800/80 hover:border-slate-700'}"
          >
            <div class="flex items-center gap-2.5 min-w-0">
              <span class="text-indigo-400 text-base">📌</span>
              <div class="min-w-0">
                <span class="font-bold text-slate-200 block truncate">Текущая папка: [ {currentPath} ]</span>
                <span class="text-[10px] text-slate-500 block truncate">Использовать эту папку как рабочую директорию</span>
              </div>
            </div>
            {#if selectedPath === currentPath}
              <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-600 text-white shrink-0">Выбрано</span>
            {/if}
          </div>

          <!-- Subfolders List -->
          {#if dirs.length === 0}
            <div class="py-10 text-center text-slate-500 text-xs">
              В этой папке нет подпапок. Вы можете нажать «Создать папку» выше или выбрать текущую папку.
            </div>
          {:else}
            <div class="space-y-1.5 pt-1">
              <span class="text-[11px] font-semibold text-slate-400 px-1 block">Структура подпапок:</span>
              {#each dirs as d}
                <div
                  on:click={() => selectedPath = d.path}
                  on:dblclick={() => loadDirectory(d.path)}
                  class="p-2.5 rounded-xl border transition flex items-center justify-between cursor-pointer {selectedPath === d.path ? 'bg-indigo-950/70 border-indigo-500 ring-1 ring-indigo-500/50' : 'bg-slate-950/60 border-slate-800 hover:border-slate-700'}"
                >
                  <div class="flex items-center gap-2.5 min-w-0">
                    <span class="text-base text-amber-400">📁</span>
                    <div class="min-w-0">
                      <span class="font-semibold text-slate-200 block truncate">{d.name}</span>
                      <span class="text-[10px] font-mono text-slate-500 truncate block">{d.path}</span>
                    </div>
                  </div>

                  <div class="flex items-center gap-2 shrink-0">
                    {#if d.subdir_count > 0}
                      <span class="text-[10px] text-slate-400 bg-slate-900 px-1.5 py-0.5 rounded border border-slate-800">
                        {d.subdir_count} папок
                      </span>
                    {/if}

                    <button
                      type="button"
                      on:click|stopPropagation={() => loadDirectory(d.path)}
                      class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-medium border border-slate-700 transition cursor-pointer"
                      title="Перейти внутрь этой папки"
                    >
                      Открыть →
                    </button>

                    {#if selectedPath === d.path}
                      <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-600 text-white">Выбрано</span>
                    {/if}
                  </div>
                </div>
              {/each}
            </div>
          {/if}
        {/if}
      </div>

      <!-- Footer -->
      <div class="px-5 py-3.5 border-t border-slate-800 flex items-center justify-between bg-slate-950/60">
        <div class="min-w-0 flex-1 mr-3">
          <span class="text-[10px] text-slate-400 block uppercase font-semibold">Выбранный путь:</span>
          <span class="font-mono text-xs text-indigo-300 font-bold truncate block">{selectedPath || currentPath}</span>
        </div>

        <div class="flex items-center gap-2 shrink-0">
          <button
            on:click={onClose}
            class="px-3.5 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition cursor-pointer"
          >
            Отмена
          </button>
          <button
            on:click={handleConfirm}
            class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white text-xs font-semibold transition flex items-center gap-1.5 shadow-md cursor-pointer"
          >
            <span>✓</span>
            <span>Выбрать эту папку</span>
          </button>
        </div>
      </div>
    </div>
  </div>
{/if}