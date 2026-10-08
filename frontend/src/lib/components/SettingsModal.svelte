<script lang="ts">
  import { onMount } from 'svelte';
  import { projectsList, activeProjectId, activeProject, fetchProjects, setActiveProject, deleteProject as apiDeleteProject } from '../stores/projectStore';
  import { systemSettingsStore, fetchSystemSettings, saveSystemSettings as apiSaveSettings } from '../stores/settingsStore';
  import FolderPickerModal from './FolderPickerModal.svelte';

  export let isOpen = false;
  export let onClose: () => void = () => {};

  let activeSection: 'project' | 'team' | 'llm' | 'general' = 'project';
  let saveStatus = '';

  // 1. Projects State
  let projects: any[] = [];
  let isCreatingNewProject = false;
  let isFolderPickerOpen = false;

  let currentProject: any = {
    id: 'proj_default',
    name: 'Default Target Project',
    workspace_path: '/workspace',
    stack: 'php-fullstack',
    default_container: 'app-container',
    guidelines_file: 'README.md',
    default_team_id: 'team_core',
    project_prompt: '',
  };

  // 2. Teams State
  let teams: any[] = [];
  let selectedTeamId = 'team_core';
  let newTeamName = '';

  // 3. Agents State
  let allAgents: any[] = [];
  let isCreatingNewAgent = false;
  let newAgent: any = {
    id: '',
    name: '',
    role: '',
    system_prompt: '',
    model: 'gpt-4o',
    temperature: 0.2,
    token_limit: 65536,
    allowed_skills: ['read_file', 'write_file', 'list_dir'],
    llm_profile_id: '',
    allowed_sub_agent_ids: [],
  };

  // 4. LLM Profiles State
  let profiles: any[] = [];
  let activeProfileId = 'prof_openai';
  let selectedProfile: any = {
    id: '',
    name: '',
    base_url: 'https://api.openai.com/v1',
    api_key: '',
    default_model: 'gpt-4o',
    is_active: false,
  };

  // 5. System Execution & Reliability Settings
  let systemSettings: any = {
    subagent_max_steps: 15,
    root_max_steps: 25,
    subagent_max_tokens: 100000,
    subagent_context_tokens: 65536,
    loop_protection_enabled: true,
    loop_detection_threshold: 3,
    llm_max_retries: 3,
    llm_retry_delay_sec: 3,
    global_system_prompt: '',
  };

  const allAvailableSkills = [
    { id: 'todo_write', name: 'Todo Write', desc: 'Формирование и обновление TODO листа с отслеживанием прогресса' },
    { id: 'memory_save', name: 'Memory Save', desc: 'Сохранение постоянной памяти агента о проекте (стек, команды, особенности)' },
    { id: 'edit_file', name: 'Edit File', desc: 'Точечное редактирование файлов с наглядным diff в двухоконном режиме' },
    { id: 'grep_search', name: 'Grep Search', desc: 'Быстрый полнотекстовый поиск по содержимому файлов с номерами строк' },
    { id: 'file_find', name: 'File Find', desc: 'Поиск файлов по имени и glob-маске без обхода папок' },
    { id: 'read_file', name: 'Read File', desc: 'Чтение файлов в рабочей директории' },
    { id: 'write_file', name: 'Write File', desc: 'Создание и редактирование файлов' },
    { id: 'list_dir', name: 'List Directory', desc: 'Просмотр папок и структуры проекта' },
    { id: 'docker_exec', name: 'Docker Exec', desc: 'Выполнение команд и тестов в Docker' },
    { id: 'host_exec', name: 'Host Exec', desc: 'Выполнение shell-команд в окружении агента' },
    { id: 'call_sub_agent', name: 'Call Sub-Agent', desc: 'Вызов подчиненных саб-агентов' },
    { id: 'ask_human_expert', name: 'Ask Human Expert', desc: 'Запрос подтверждения у человека' },
    { id: 'browse_link', name: 'Browser MCP (Browse Link)', desc: 'Открытие ссылок в headless браузере Chromium через MCP контейнер' },
  ];

  $: if (isOpen) {
    loadAllData();
  }

  async function loadAllData() {
    await Promise.all([
      loadProjects(),
      loadTeams(),
      loadAgents(),
      loadLLMProfiles(),
      fetchSystemSettings(),
    ]);
  }

  // --- Projects API ---
  async function loadProjects() {
    try {
      const res = await fetch('/api/projects');
      if (res.ok) {
        projects = await res.json();
        projectsList.set(projects);
        if (projects.length > 0) {
          const actId = $activeProjectId;
          const found = projects.find(p => p.id === (isCreatingNewProject ? currentProject.id : actId)) || projects[0];
          if (!isCreatingNewProject) {
            currentProject = { ...found };
          }
        }
      }
    } catch (e) {
      console.warn('Failed to load projects:', e);
    }
  }

  function handleSelectProjectToEdit(proj: any) {
    isCreatingNewProject = false;
    currentProject = { ...proj };
  }

  function startCreateNewProject() {
    isCreatingNewProject = true;
    const newId = 'proj_' + Math.random().toString(36).substring(2, 8);
    currentProject = {
      id: newId,
      name: 'Новый проект',
      workspace_path: '/workspace/new-project',
      stack: 'general',
      default_container: 'app-container',
      guidelines_file: 'README.md',
      default_team_id: teams[0]?.id || 'team_core',
      project_prompt: '',
    };
  }

  function onProjectNameInput() {
    if (isCreatingNewProject) {
      const slug = currentProject.name
        .toLowerCase()
        .replace(/[^a-z0-9а-яё_-]/gi, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
      if (slug) {
        currentProject.workspace_path = `/workspace/${slug}`;
      }
    }
  }

  async function saveProject() {
    if (!currentProject.name.trim()) return;
    try {
      saveStatus = 'Сохранение проекта...';
      const res = await fetch('/api/projects', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(currentProject),
      });
      if (res.ok) {
        saveStatus = '✓ Проект успешно сохранен!';
        isCreatingNewProject = false;
        await loadProjects();
        setActiveProject(currentProject.id);
        setTimeout(() => saveStatus = '', 2000);
      } else {
        saveStatus = 'Ошибка сохранения проекта';
      }
    } catch (e) {
      saveStatus = 'Ошибка сети';
    }
  }

  async function handleDeleteProject(id: string) {
    const projToDelete = projects.find(p => p.id === id);
    const projName = projToDelete?.name || id;
    if (!confirm(`Вы действительно хотите удалить проект «${projName}»?\n\nВсе связанные задачи этого проекта будут удалены.`)) {
      return;
    }
    saveStatus = 'Удаление проекта...';
    try {
      const ok = await apiDeleteProject(id);
      if (ok) {
        saveStatus = '✓ Проект удален';
        await loadProjects();
        if (currentProject.id === id && projects.length > 0) {
          currentProject = { ...projects[0] };
        }
        setTimeout(() => saveStatus = '', 2000);
      } else {
        saveStatus = 'Ошибка при удалении проекта';
      }
    } catch (e) {
      saveStatus = 'Ошибка удаления';
    }
  }

  function handleFolderSelected(path: string) {
    currentProject.workspace_path = path;
  }

  // --- Teams & Agents API ---
  async function loadTeams() {
    try {
      const res = await fetch('/api/teams');
      if (res.ok) {
        teams = await res.json();
        if (teams.length > 0 && !teams.find(t => t.id === selectedTeamId)) {
          selectedTeamId = teams[0].id;
        }
      }
    } catch (e) {
      console.warn('Failed to load teams:', e);
    }
  }

  async function loadAgents() {
    try {
      const res = await fetch('/api/agents');
      if (res.ok) {
        allAgents = await res.json();
      }
    } catch (e) {
      console.warn('Failed to load agents:', e);
    }
  }

  $: currentTeam = teams.find(t => t.id === selectedTeamId) || (teams.length > 0 ? teams[0] : null);
  $: currentTeamMembers = allAgents.filter(a => currentTeam?.member_agent_ids?.includes(a.id));
  $: availableNotInTeam = allAgents.filter(a => !currentTeam?.member_agent_ids?.includes(a.id));

  async function saveCurrentTeam() {
    if (!currentTeam) return;
    try {
      saveStatus = 'Сохранение команды...';
      const res = await fetch('/api/teams', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(currentTeam),
      });
      if (res.ok) {
        saveStatus = '✓ Команда сохранена!';
        await loadTeams();
        setTimeout(() => saveStatus = '', 1500);
      }
    } catch (e) {
      saveStatus = 'Ошибка сохранения команды';
    }
  }

  async function createNewTeam() {
    const name = newTeamName.trim() || `Team ${teams.length + 1}`;
    const newTeam = {
      id: 'team_' + Math.random().toString(36).substring(2, 8),
      project_id: currentProject.id,
      name,
      lead_agent_id: allAgents[0]?.id || 'agent_manager',
      member_agent_ids: allAgents.map(a => a.id),
    };
    try {
      const res = await fetch('/api/teams', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(newTeam),
      });
      if (res.ok) {
        await loadTeams();
        selectedTeamId = newTeam.id;
        newTeamName = '';
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function deleteTeam(id: string) {
    if (teams.length <= 1) return;
    if (!confirm('Вы действительно хотите удалить эту команду саб-агентов?')) return;
    try {
      await fetch(`/api/teams?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
      await loadTeams();
    } catch (e) {
      console.error(e);
    }
  }

  function toggleAgentInTeam(agentId: string) {
    if (!currentTeam) return;
    const members = [...(currentTeam.member_agent_ids || [])];
    const idx = members.indexOf(agentId);
    if (idx >= 0) {
      if (members.length <= 1) {
        alert('В команде должен быть как минимум один агент.');
        return;
      }
      members.splice(idx, 1);
      if (currentTeam.lead_agent_id === agentId) {
        currentTeam.lead_agent_id = members[0];
      }
    } else {
      members.push(agentId);
    }
    currentTeam.member_agent_ids = members;
    teams = [...teams];
  }

  function addAgentToTeam(agentId: string) {
    if (!currentTeam) return;
    const members = [...(currentTeam.member_agent_ids || [])];
    if (!members.includes(agentId)) {
      members.push(agentId);
      currentTeam.member_agent_ids = members;
      teams = [...teams];
      saveCurrentTeam();
    }
  }

  function removeAgentFromTeam(agentId: string) {
    if (!currentTeam) return;
    if ((currentTeam.member_agent_ids || []).length <= 1) {
      alert('В команде должен оставаться как минимум один саб-агент.');
      return;
    }
    const agent = allAgents.find(a => a.id === agentId);
    const agentName = agent?.name || agentId;
    if (!confirm(`Исключить роль «${agentName}» из состава команды «${currentTeam.name}»?\n\n(Сама роль сохранится в системе и доступна для добавления в команды)`)) {
      return;
    }
    toggleAgentInTeam(agentId);
    saveCurrentTeam();
  }

  function onTeamLeadChange() {
    if (!currentTeam) return;
    if (!currentTeam.member_agent_ids.includes(currentTeam.lead_agent_id)) {
      currentTeam.member_agent_ids = [...currentTeam.member_agent_ids, currentTeam.lead_agent_id];
    }
    saveCurrentTeam();
  }

  function setAsTeamLead(agentId: string) {
    if (!currentTeam) return;
    currentTeam.lead_agent_id = agentId;
    if (!currentTeam.member_agent_ids.includes(agentId)) {
      currentTeam.member_agent_ids = [...currentTeam.member_agent_ids, agentId];
    }
    teams = [...teams];
    saveCurrentTeam();
  }

  // --- Agents (Roles) CRUD ---
  function startCreateNewAgent() {
    isCreatingNewAgent = true;
    const rnd = Math.random().toString(36).substring(2, 6);
    const activeProf = profiles.find(p => p.id === activeProfileId) || profiles[0];
    newAgent = {
      id: 'agent_' + rnd,
      name: '',
      role: '',
      system_prompt: '',
      model: activeProf?.default_model || 'gpt-4o',
      temperature: 0.2,
      token_limit: 65536,
      allowed_skills: ['read_file', 'write_file', 'list_dir'],
      llm_profile_id: activeProfileId || '',
      allowed_sub_agent_ids: [],
    };
  }

  function onNewAgentNameInput() {
    if (!newAgent.role || newAgent.role.startsWith('role_') || newAgent.role === '') {
      const slug = newAgent.name
        .toLowerCase()
        .replace(/[^a-z0-9_-]/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '');
      if (slug) {
        newAgent.role = slug;
        newAgent.id = 'agent_' + slug;
      }
    }
  }

  function toggleSkillForNewAgent(skillId: string) {
    const list = [...(newAgent.allowed_skills || [])];
    const idx = list.indexOf(skillId);
    if (idx >= 0) {
      list.splice(idx, 1);
    } else {
      list.push(skillId);
    }
    newAgent.allowed_skills = list;
    newAgent = { ...newAgent };
  }

  async function createAgentAndAddToTeam() {
    if (!newAgent.name.trim()) {
      alert('Пожалуйста, введите название новой роли саб-агента.');
      return;
    }
    if (!newAgent.role.trim()) {
      newAgent.role = 'role_' + Math.random().toString(36).substring(2, 6);
    }
    if (!newAgent.id.trim()) {
      newAgent.id = 'agent_' + newAgent.role;
    }

    try {
      saveStatus = 'Создание роли...';
      const res = await fetch('/api/agents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(newAgent),
      });
      if (res.ok) {
        saveStatus = '✓ Роль успешно создана!';
        await loadAgents();

        // Add to current team automatically
        if (currentTeam) {
          const members = [...(currentTeam.member_agent_ids || [])];
          if (!members.includes(newAgent.id)) {
            members.push(newAgent.id);
            currentTeam.member_agent_ids = members;
            await saveCurrentTeam();
          }
        }

        isCreatingNewAgent = false;
        setTimeout(() => saveStatus = '', 1500);
      } else {
        saveStatus = 'Ошибка создания роли';
      }
    } catch (e) {
      saveStatus = 'Ошибка сети';
    }
  }

  async function deleteAgent(agentId: string) {
    const agent = allAgents.find(a => a.id === agentId);
    const agentName = agent?.name || agentId;
    if (!confirm(`Вы действительно хотите безвозвратно удалить роль «${agentName}» (${agent?.role})?\n\nРоль будет удалена из базы данных и исключена из состава всех команд.`)) {
      return;
    }
    try {
      saveStatus = `Удаление ${agentName}...`;
      const res = await fetch(`/api/agents?id=${encodeURIComponent(agentId)}`, {
        method: 'DELETE',
      });
      if (res.ok) {
        saveStatus = `✓ Роль «${agentName}» удалена`;
        await loadAgents();
        await loadTeams();
        setTimeout(() => saveStatus = '', 1500);
      } else {
        saveStatus = 'Ошибка при удалении роли';
      }
    } catch (e) {
      saveStatus = 'Ошибка сети';
    }
  }

  async function saveAgent(agent: any) {
    try {
      saveStatus = `Сохранение ${agent.name}...`;
      const res = await fetch('/api/agents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(agent),
      });
      if (res.ok) {
        saveStatus = '✓ Агент обновлен!';
        await loadAgents();
        setTimeout(() => saveStatus = '', 1500);
      }
    } catch (e) {
      saveStatus = 'Ошибка обновления агента';
    }
  }

  function onAgentProfileChange(agent: any) {
    if (agent.llm_profile_id) {
      const prof = profiles.find(p => p.id === agent.llm_profile_id);
      if (prof && prof.default_model) {
        agent.model = prof.default_model;
      }
    }
    saveAgent(agent);
  }

  function toggleSkillForAgent(agent: any, skillId: string) {
    const skills = [...(agent.allowed_skills || [])];
    const idx = skills.indexOf(skillId);
    if (idx >= 0) {
      skills.splice(idx, 1);
    } else {
      skills.push(skillId);
    }
    agent.allowed_skills = skills;
    allAgents = [...allAgents];
    saveAgent(agent);
  }

  function toggleCallableSubAgent(agent: any, targetId: string) {
    const list = [...(agent.allowed_sub_agent_ids || [])];
    const idx = list.indexOf(targetId);
    if (idx >= 0) {
      list.splice(idx, 1);
    } else {
      list.push(targetId);
    }
    agent.allowed_sub_agent_ids = list;
    allAgents = [...allAgents];
    saveAgent(agent);
  }

  // --- LLM Profiles API ---
  async function loadLLMProfiles() {
    try {
      const res = await fetch('/api/llm-profiles');
      if (res.ok) {
        const data = await res.json();
        profiles = data.profiles || [];
        activeProfileId = data.active_profile_id || (profiles[0]?.id || '');
        const active = profiles.find(p => p.id === activeProfileId) || profiles[0];
        if (active) {
          selectedProfile = { ...active };
        }
      }
    } catch (e) {
      console.warn('Failed to load LLM profiles:', e);
    }
  }

  async function activateProfile(id: string) {
    try {
      saveStatus = 'Активация профиля LLM...';
      const res = await fetch('/api/llm-profiles', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'activate', profile_id: id }),
      });
      if (res.ok) {
        activeProfileId = id;
        saveStatus = '✓ Профиль активен!';
        await loadLLMProfiles();
        setTimeout(() => saveStatus = '', 1500);
      }
    } catch (e) {
      saveStatus = 'Ошибка активации профиля';
    }
  }

  async function saveProfile() {
    if (!selectedProfile.name.trim()) return;
    try {
      saveStatus = 'Сохранение профиля...';
      const res = await fetch('/api/llm-profiles', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save', profile: selectedProfile }),
      });
      if (res.ok) {
        saveStatus = '✓ Профиль сохранен!';
        await loadLLMProfiles();
        setTimeout(() => saveStatus = '', 1500);
      }
    } catch (e) {
      saveStatus = 'Ошибка сохранения профиля';
    }
  }

  async function deleteProfile(id: string) {
    if (profiles.length <= 1) return;
    if (!confirm('Вы уверены, что хотите удалить этот профиль?')) return;
    try {
      await fetch('/api/llm-profiles', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', profile_id: id }),
      });
      await loadLLMProfiles();
    } catch (e) {
      console.error(e);
    }
  }

  function startNewProfile() {
    selectedProfile = {
      id: 'prof_' + Math.random().toString(36).substring(2, 8),
      name: 'Новый LLM профиль',
      base_url: 'https://api.openai.com/v1',
      api_key: '',
      default_model: 'gpt-4o',
      is_active: false,
    };
  }

  // --- System Settings API & Sync with Store ---
  $: if ($systemSettingsStore && !saveStatus) {
    systemSettings = { ...$systemSettingsStore };
  }

  async function saveSystemSettings() {
    try {
      saveStatus = 'Сохранение параметров...';
      const ok = await apiSaveSettings({
        subagent_max_steps: Number(systemSettings.subagent_max_steps),
        root_max_steps: Number(systemSettings.root_max_steps),
        subagent_max_tokens: Number(systemSettings.subagent_max_tokens),
        subagent_context_tokens: Number(systemSettings.subagent_context_tokens || 65536),
        loop_protection_enabled: Boolean(systemSettings.loop_protection_enabled),
        loop_detection_threshold: Number(systemSettings.loop_detection_threshold),
        llm_max_retries: Number(systemSettings.llm_max_retries),
        llm_retry_delay_sec: Number(systemSettings.llm_retry_delay_sec),
        global_system_prompt: String(systemSettings.global_system_prompt || ''),
      });
      if (ok) {
        saveStatus = '✓ Параметры успешно сохранены!';
        setTimeout(() => saveStatus = '', 2000);
      } else {
        saveStatus = 'Ошибка сохранения параметров';
      }
    } catch (e) {
      saveStatus = 'Ошибка сети';
    }
  }

  function handleAutoSaveSystemSettings() {
    apiSaveSettings({
      subagent_max_steps: Number(systemSettings.subagent_max_steps),
      root_max_steps: Number(systemSettings.root_max_steps),
      subagent_max_tokens: Number(systemSettings.subagent_max_tokens),
      subagent_context_tokens: Number(systemSettings.subagent_context_tokens || 65536),
      loop_protection_enabled: Boolean(systemSettings.loop_protection_enabled),
      loop_detection_threshold: Number(systemSettings.loop_detection_threshold),
      llm_max_retries: Number(systemSettings.llm_max_retries),
      llm_retry_delay_sec: Number(systemSettings.llm_retry_delay_sec),
      global_system_prompt: String(systemSettings.global_system_prompt || ''),
    });
  }

  function handleCloseModal() {
    if (activeSection === 'general') {
      handleAutoSaveSystemSettings();
    }
    onClose();
  }
</script>

{#if isOpen}
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/85 backdrop-blur-sm select-none">
    <div class="w-full max-w-5xl rounded-2xl bg-slate-900 border border-slate-800 shadow-2xl flex flex-col max-h-[92vh] overflow-hidden text-slate-100">
      <!-- Modal Header -->
      <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between bg-slate-950/60">
        <div class="flex items-center gap-2.5">
          <span class="text-xl">⚙️</span>
          <div>
            <h2 class="font-bold text-base text-slate-100">Управление проектами, командами и окружением</h2>
            <p class="text-[11px] text-slate-400">Изоляция проектов, управление ролями саб-агентов, LLM провайдеры и параметры надежности</p>
          </div>
        </div>
        <button on:click={handleCloseModal} class="text-slate-500 hover:text-slate-300 text-lg cursor-pointer">✕</button>
      </div>

      <!-- Nav Tabs -->
      <div class="flex border-b border-slate-800 text-xs px-6 bg-slate-950/40">
        <button
          on:click={() => activeSection = 'project'}
          class="py-3 px-4 border-b-2 font-medium transition flex items-center gap-1.5 cursor-pointer {activeSection === 'project' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-slate-200'}"
        >
          <span>📁</span>
          <span>Проекты ({projects.length})</span>
        </button>
        <button
          on:click={() => activeSection = 'team'}
          class="py-3 px-4 border-b-2 font-medium transition flex items-center gap-1.5 cursor-pointer {activeSection === 'team' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-slate-200'}"
        >
          <span>👥</span>
          <span>Команды и Роли саб-агентов ({teams.length})</span>
        </button>
        <button
          on:click={() => activeSection = 'llm'}
          class="py-3 px-4 border-b-2 font-medium transition flex items-center gap-1.5 cursor-pointer {activeSection === 'llm' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-slate-200'}"
        >
          <span>🤖</span>
          <span>LLM Провайдеры ({profiles.length})</span>
        </button>
        <button
          on:click={() => activeSection = 'general'}
          class="py-3 px-4 border-b-2 font-medium transition flex items-center gap-1.5 cursor-pointer {activeSection === 'general' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-slate-400 hover:text-slate-200'}"
        >
          <span>⚙️</span>
          <span>Параметры и Защита</span>
        </button>
      </div>

      <!-- Modal Body -->
      <div class="flex-1 overflow-y-auto p-6 space-y-4 text-xs font-sans">
        <!-- ================= SECTION 1: TARGET PROJECTS MANAGEMENT ================= -->
        {#if activeSection === 'project'}
          <div class="space-y-6">
            <!-- Header bar with create project button -->
            <div class="flex items-center justify-between bg-slate-950/70 p-3.5 rounded-xl border border-slate-800">
              <div>
                <span class="font-bold text-sm text-slate-200 block">Список проектов</span>
                <span class="text-[11px] text-slate-400">Каждый проект изолирован в своей отдельной папке в /workspace</span>
              </div>
              <button
                on:click={startCreateNewProject}
                class="px-3.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white text-xs font-semibold transition flex items-center gap-1.5 shadow-sm cursor-pointer"
              >
                <span>➕</span>
                <span>Создать проект</span>
              </button>
            </div>

            <!-- Projects Grid Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              {#each projects as proj}
                {@const isActive = $activeProjectId === proj.id}
                {@const isSelected = currentProject.id === proj.id && !isCreatingNewProject}
                <div
                  on:click={() => handleSelectProjectToEdit(proj)}
                  class="p-3.5 rounded-xl border transition flex flex-col justify-between cursor-pointer {isActive ? 'border-indigo-500 bg-indigo-950/30 ring-1 ring-indigo-500/50' : isSelected ? 'border-slate-600 bg-slate-900' : 'border-slate-800 bg-slate-950/60 hover:border-slate-700'}"
                >
                  <div class="space-y-1.5">
                    <div class="flex items-start justify-between gap-1">
                      <span class="font-bold text-xs text-slate-100 truncate block">{proj.name}</span>
                      {#if isActive}
                        <span class="px-2 py-0.5 rounded-full bg-emerald-950 text-emerald-400 text-[9px] font-bold uppercase border border-emerald-800/80 shrink-0">
                          ★ Текущий
                        </span>
                      {/if}
                    </div>

                    <div class="flex items-center gap-1 text-[11px] text-slate-400 font-mono truncate">
                      <span class="text-amber-400">📁</span>
                      <span class="truncate">{proj.workspace_path}</span>
                    </div>

                    <div class="flex items-center gap-2 pt-1 text-[10px]">
                      <span class="px-1.5 py-0.5 rounded bg-slate-900 border border-slate-800 text-slate-400 font-mono">{proj.stack || 'general'}</span>
                      {#if proj.default_container}
                        <span class="px-1.5 py-0.5 rounded bg-slate-900 border border-slate-800 text-cyan-400 font-mono truncate">🐳 {proj.default_container}</span>
                      {/if}
                    </div>
                  </div>

                  <div class="mt-3.5 pt-2.5 border-t border-slate-800/80 flex items-center justify-between">
                    {#if !isActive}
                      <button
                        type="button"
                        on:click|stopPropagation={() => setActiveProject(proj.id)}
                        class="text-[11px] text-indigo-400 hover:text-indigo-300 font-semibold cursor-pointer"
                      >
                        Сделать текущим →
                      </button>
                    {:else}
                      <span class="text-[10px] text-emerald-400 font-medium">Активный проект</span>
                    {/if}

                    <div class="flex items-center gap-2">
                      <button
                        type="button"
                        on:click|stopPropagation={() => handleSelectProjectToEdit(proj)}
                        class="text-[10px] text-slate-400 hover:text-slate-200 cursor-pointer"
                      >
                        Редактировать
                      </button>
                      {#if projects.length > 1}
                        <button
                          type="button"
                          on:click|stopPropagation={() => handleDeleteProject(proj.id)}
                          class="text-[11px] text-rose-400 hover:text-rose-300 p-1 cursor-pointer"
                          title="Удалить этот проект"
                        >
                          🗑️
                        </button>
                      {/if}
                    </div>
                  </div>
                </div>
              {/each}
            </div>

            <!-- Project Editor Form Card -->
            <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/70 space-y-4">
              <div class="flex items-center justify-between border-b border-slate-800/80 pb-3">
                <div class="flex items-center gap-2">
                  <span class="text-base">📝</span>
                  <h3 class="font-bold text-sm text-slate-200">
                    {isCreatingNewProject ? 'Создание нового проекта' : `Редактирование проекта: ${currentProject.name}`}
                  </h3>
                </div>

                <div class="flex items-center gap-2">
                  {#if !isCreatingNewProject && $activeProjectId !== currentProject.id}
                    <button
                      type="button"
                      on:click={() => setActiveProject(currentProject.id)}
                      class="px-3 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold transition cursor-pointer"
                    >
                      Сделать активным
                    </button>
                  {/if}

                  {#if !isCreatingNewProject && projects.length > 1}
                    <button
                      type="button"
                      on:click={() => handleDeleteProject(currentProject.id)}
                      class="px-3 py-1 rounded-lg bg-rose-950/60 hover:bg-rose-900/80 text-rose-300 text-xs font-semibold border border-rose-800/60 transition cursor-pointer"
                    >
                      Удалить проект
                    </button>
                  {/if}
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label for="proj-name-input" class="block text-slate-400 font-medium mb-1">Название проекта:</label>
                  <input
                    id="proj-name-input"
                    type="text"
                    bind:value={currentProject.name}
                    on:input={onProjectNameInput}
                    placeholder="например: Интернет-магазин"
                    class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 focus:outline-none focus:border-indigo-500 font-medium"
                  />
                </div>

                <div>
                  <label for="proj-team-select" class="block text-slate-400 font-medium mb-1">Назначенная команда саб-агентов:</label>
                  <select
                    id="proj-team-select"
                    bind:value={currentProject.default_team_id}
                    class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 focus:outline-none focus:border-indigo-500 font-medium cursor-pointer"
                  >
                    {#each teams as tm}
                      <option value={tm.id}>{tm.name}</option>
                    {/each}
                  </select>
                </div>
              </div>

              <!-- Workspace Path with Folder Browser Button -->
              <div>
                <label for="proj-workspace-input" class="block text-slate-400 font-medium mb-1 flex items-center justify-between">
                  <span>Рабочая директория проекта (Workspace Path):</span>
                  <span class="text-[10px] text-slate-500">каждый проект в отдельной папке</span>
                </label>
                <div class="flex items-center gap-2">
                  <input
                    id="proj-workspace-input"
                    type="text"
                    bind:value={currentProject.workspace_path}
                    placeholder="/workspace/project-folder"
                    class="flex-1 bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-500"
                  />
                  <button
                    type="button"
                    on:click={() => isFolderPickerOpen = true}
                    class="px-3.5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold transition flex items-center gap-1.5 shadow-sm cursor-pointer shrink-0"
                    title="Выбрать существующую папку или создать новую"
                  >
                    <span>📁</span>
                    <span>Обзор папок...</span>
                  </button>
                </div>
                <p class="text-[11px] text-slate-500 mt-1">
                  * Все файловые операции, запуск команд и поиск ограничены этой рабочей директорией. Если папка еще не создана, система создаст ее автоматически.
                </p>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label for="proj-stack-input" class="block text-slate-400 font-medium mb-1">Стек технологий:</label>
                  <input
                    id="proj-stack-input"
                    type="text"
                    bind:value={currentProject.stack}
                    placeholder="php, nodejs, python, svelte..."
                    class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 focus:outline-none focus:border-indigo-500"
                  />
                </div>

                <div>
                  <label for="proj-container-input" class="block text-slate-400 font-medium mb-1">Docker контейнер проекта:</label>
                  <input
                    id="proj-container-input"
                    type="text"
                    bind:value={currentProject.default_container}
                    placeholder="app-container"
                    class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 font-mono focus:outline-none focus:border-indigo-500"
                  />
                </div>

                <div>
                  <label for="proj-guidelines-input" class="block text-slate-400 font-medium mb-1">Файл инструкций (Guidelines):</label>
                  <input
                    id="proj-guidelines-input"
                    type="text"
                    bind:value={currentProject.guidelines_file}
                    placeholder="README.md"
                    class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 font-mono focus:outline-none focus:border-indigo-500"
                  />
                </div>
              </div>

              <!-- Project-Specific Prompt common for all tasks in this project -->
              <div>
                <label for="proj-prompt-input" class="block text-slate-300 font-medium mb-1 flex items-center justify-between">
                  <span class="flex items-center gap-1.5 text-indigo-300 font-semibold">
                    <span>📋</span>
                    <span>Промпт проекта (общий для всех задач проекта):</span>
                  </span>
                  <span class="text-[10px] text-slate-500">внедряется в контекст всех саб-агентов проекта</span>
                </label>
                <textarea
                  id="proj-prompt-input"
                  bind:value={currentProject.project_prompt}
                  rows="4"
                  placeholder="Опишите требования к коду, архитектурные соглашения, специфику окружения или правила для всех задач внутри этого проекта..."
                  class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-slate-100 text-xs focus:outline-none focus:border-indigo-500 resize-y min-h-[120px] max-h-[600px] leading-relaxed font-sans"
                ></textarea>
                <p class="text-[10px] text-slate-500 mt-1">
                  * Этот промпт автоматически добавляется в системный контекст каждого саб-агента при выполнении любых задач в рамках данного проекта.
                </p>
              </div>

              <div class="pt-2 flex items-center justify-between border-t border-slate-800/80">
                <button
                  type="button"
                  on:click={saveProject}
                  class="px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-semibold transition text-xs shadow-md cursor-pointer flex items-center gap-1.5"
                >
                  <span>💾</span>
                  <span>{isCreatingNewProject ? 'Создать и сохранить проект' : 'Сохранить настройки проекта'}</span>
                </button>

                {#if isCreatingNewProject}
                  <button
                    type="button"
                    on:click={() => { isCreatingNewProject = false; if (projects.length > 0) currentProject = { ...projects[0] }; }}
                    class="px-3.5 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs transition cursor-pointer"
                  >
                    Отмена
                  </button>
                {/if}
              </div>
            </div>
          </div>

        <!-- ================= SECTION 2: TEAMS & AGENTS (ROLES) ================= -->
        {:else if activeSection === 'team'}
          <div class="space-y-6">
            <!-- Team selector and creator bar -->
            <div class="flex items-center justify-between bg-slate-950/70 p-3 rounded-xl border border-slate-800">
              <div class="flex items-center gap-3">
                <span class="text-slate-400 font-medium">Выберите команду:</span>
                <div class="flex items-center gap-2 flex-wrap">
                  {#each teams as tm}
                    <button
                      on:click={() => selectedTeamId = tm.id}
                      class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border cursor-pointer {selectedTeamId === tm.id ? 'bg-indigo-600 text-white border-indigo-500 shadow' : 'bg-slate-900 text-slate-300 border-slate-800 hover:border-slate-700'}"
                    >
                      {tm.name}
                    </button>
                  {/each}
                </div>
              </div>

              <!-- Create new team -->
              <div class="flex items-center gap-2">
                <input
                  type="text"
                  bind:value={newTeamName}
                  placeholder="Имя новой команды..."
                  class="bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1 text-xs text-slate-100 focus:outline-none focus:border-indigo-500"
                />
                <button
                  on:click={createNewTeam}
                  class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium border border-slate-700 cursor-pointer"
                >
                  + Создать команду
                </button>
              </div>
            </div>

            {#if currentTeam}
              <!-- Team Meta Config -->
              <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/40 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label for="team-name-input" class="block text-slate-400 font-medium mb-1">Название команды:</label>
                    <input id="team-name-input" type="text" bind:value={currentTeam.name} on:blur={saveCurrentTeam} class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 focus:outline-none focus:border-indigo-500 font-medium" />
                  </div>
                  <div>
                    <label for="team-lead-select" class="block text-amber-400 font-medium mb-1 flex items-center gap-1.5">
                      <span>👑</span> Главный саб-агент команды (Primary Task Receiver)
                    </label>
                    <select
                      id="team-lead-select"
                      bind:value={currentTeam.lead_agent_id}
                      on:change={onTeamLeadChange}
                      class="w-full bg-slate-950 border border-amber-600/70 rounded-lg p-2.5 text-slate-100 focus:outline-none focus:border-amber-400 font-medium ring-1 ring-amber-500/20 cursor-pointer"
                    >
                      {#each allAgents as agent}
                        <option value={agent.id}>
                          {agent.name} ({agent.role}) {currentTeam.member_agent_ids?.includes(agent.id) ? '' : '[добавится в команду]'}
                        </option>
                      {/each}
                    </select>
                  </div>
                </div>

                <div class="text-[11px] text-slate-400 bg-amber-950/20 border border-amber-900/40 rounded-lg p-2.5 flex items-center gap-2">
                  <span class="text-amber-400 text-sm">👑</span>
                  <span>
                    <strong>Главный саб-агент</strong> получает входящую задачу от пользователя как корневой узел сессии (Root Node). Он первым запускает цикл ReAct, распределяет работу среди подчиненных саб-агентов и формулирует итоговый результат.
                  </span>
                </div>

                <!-- Members toggles & Add role button -->
                <div>
                  <div class="flex items-center justify-between mb-1.5">
                    <span class="block text-slate-400 font-medium">Состав саб-агентов в команде:</span>
                    <button
                      type="button"
                      on:click={startCreateNewAgent}
                      class="px-2.5 py-1 rounded-lg bg-indigo-600/30 hover:bg-indigo-600/50 text-indigo-300 border border-indigo-500/40 text-[11px] font-semibold transition flex items-center gap-1.5 cursor-pointer shadow-sm"
                    >
                      <span>➕</span>
                      <span>Создать новую роль</span>
                    </button>
                  </div>

                  <div class="flex flex-wrap gap-2 items-center">
                    {#each allAgents as a}
                      {@const isMember = currentTeam?.member_agent_ids?.includes(a.id)}
                      {@const isLead = currentTeam?.lead_agent_id === a.id}
                      <button
                        type="button"
                        on:click={() => toggleAgentInTeam(a.id)}
                        class="px-2.5 py-1 rounded-lg border text-xs font-medium transition flex items-center gap-1.5 cursor-pointer {isLead ? 'bg-amber-950/80 border-amber-500 text-amber-200' : isMember ? 'bg-indigo-950/80 border-indigo-600 text-indigo-200' : 'bg-slate-900 border-slate-800 text-slate-500 hover:text-slate-300'}"
                        title={isMember ? 'Кликните, чтобы исключить из команды' : 'Кликните, чтобы включить в команду'}
                      >
                        <span>{isLead ? '👑' : isMember ? '✓' : '+'}</span>
                        <span>{a.name}</span>
                        <span class="text-[10px] opacity-70 uppercase">({a.role})</span>
                      </button>
                    {/each}

                    <button
                      type="button"
                      on:click={startCreateNewAgent}
                      class="px-2.5 py-1 rounded-lg border border-dashed border-indigo-700/60 bg-indigo-950/20 hover:bg-indigo-900/40 text-indigo-300 text-xs font-medium transition flex items-center gap-1 cursor-pointer"
                      title="Создать новую роль саб-агента"
                    >
                      <span>➕</span>
                      <span>Новая роль</span>
                    </button>
                  </div>
                </div>

                <div class="flex items-center justify-between pt-2">
                  <button on:click={saveCurrentTeam} class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-medium transition text-xs shadow cursor-pointer">
                    Сохранить структуру команды
                  </button>
                  {#if teams.length > 1}
                    <button on:click={() => deleteTeam(currentTeam.id)} class="text-rose-400 hover:text-rose-300 text-xs transition cursor-pointer">
                      Удалить команду
                    </button>
                  {/if}
                </div>
              </div>

              <!-- ================= Inline Role Creator Form ================= -->
              {#if isCreatingNewAgent}
                <div class="p-5 rounded-xl border border-indigo-500/70 bg-indigo-950/30 space-y-4 shadow-xl ring-1 ring-indigo-500/30 animate-in fade-in duration-200">
                  <div class="flex items-center justify-between border-b border-indigo-800/60 pb-3">
                    <div class="flex items-center gap-2">
                      <span class="text-base">➕</span>
                      <div>
                        <h4 class="font-bold text-sm text-indigo-200">Создание новой роли саб-агента</h4>
                        <p class="text-[10px] text-slate-400">Роль будет сохранена в системе и автоматически добавлена в текущую команду «{currentTeam.name}»</p>
                      </div>
                    </div>
                    <button
                      type="button"
                      on:click={() => isCreatingNewAgent = false}
                      class="text-slate-400 hover:text-slate-200 text-sm cursor-pointer p-1"
                    >
                      ✕
                    </button>
                  </div>

                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label for="new-agent-name" class="block text-slate-300 font-medium mb-1">
                        Название роли (Display Name):
                      </label>
                      <input
                        id="new-agent-name"
                        type="text"
                        bind:value={newAgent.name}
                        on:input={onNewAgentNameInput}
                        placeholder="например: DevOps / SRE Engineer, Security Auditor..."
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg p-2.5 text-slate-100 focus:outline-none focus:border-indigo-400 font-medium text-xs"
                      />
                    </div>

                    <div>
                      <label for="new-agent-role" class="block text-slate-300 font-medium mb-1">
                        Идентификатор роли (Role ID / Slug):
                      </label>
                      <input
                        id="new-agent-role"
                        type="text"
                        bind:value={newAgent.role}
                        placeholder="например: devops, security, data_engineer"
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-400"
                      />
                    </div>
                  </div>

                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label for="new-agent-profile" class="block text-slate-300 font-medium mb-1">
                        LLM Профиль:
                      </label>
                      <select
                        id="new-agent-profile"
                        bind:value={newAgent.llm_profile_id}
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg p-2.5 text-slate-200 font-medium text-xs focus:outline-none focus:border-indigo-400 cursor-pointer"
                      >
                        <option value="">(По умолчанию)</option>
                        {#each profiles as prof}
                          <option value={prof.id}>{prof.name} ({prof.default_model})</option>
                        {/each}
                      </select>
                    </div>

                    <div>
                      <label for="new-agent-model" class="block text-slate-300 font-medium mb-1">
                        Модель:
                      </label>
                      <input
                        id="new-agent-model"
                        type="text"
                        bind:value={newAgent.model}
                        placeholder="gpt-4o, claude-3-5-sonnet, deepseek-chat..."
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-400"
                      />
                    </div>
                  </div>

                  <div>
                    <label for="new-agent-prompt" class="block text-slate-300 font-medium mb-1">
                      System Prompt / Описание обязанностей роли:
                    </label>
                    <textarea
                      id="new-agent-prompt"
                      bind:value={newAgent.system_prompt}
                      rows="4"
                      placeholder="Опишите, какие задачи решает этот саб-агент, какой стек использует и как формулирует ответы..."
                      class="w-full bg-slate-950 border border-slate-700 rounded-lg p-3 text-slate-100 text-xs focus:outline-none focus:border-indigo-400 resize-y min-h-[120px] max-h-[600px] leading-relaxed font-sans"
                    ></textarea>
                  </div>

                  <!-- Persistent Project Memory for this Agent -->
                  {#if !isCreatingNewAgent && newAgent.id}
                    <div class="p-3.5 rounded-xl bg-slate-950/80 border border-indigo-900/50 space-y-2 shadow-sm">
                      <div class="flex items-center justify-between">
                        <label for="agent-memory-box" class="flex items-center gap-1.5 text-xs font-bold text-indigo-300">
                          <span>🧠</span>
                          <span>Постоянная память саб-агента (Проект: {$activeProject?.name || 'текущий'})</span>
                        </label>
                        <div class="flex items-center gap-2">
                          {#if memoryState.updatedAt}
                            <span class="text-[10px] text-slate-500 font-mono">Обновлено: {memoryState.updatedAt}</span>
                          {/if}
                          <button
                            type="button"
                            on:click={handleSaveAgentMemory}
                            disabled={memoryState.isSaving}
                            class="px-2.5 py-1 rounded bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-medium text-[11px] transition flex items-center gap-1 cursor-pointer"
                          >
                            <span>💾</span>
                            <span>{memoryState.isSaving ? 'Сохранение...' : 'Сохранить память'}</span>
                          </button>
                        </div>
                      </div>
                      <p class="text-[11px] text-slate-400 leading-normal">
                        Память сохраняется в базе и автоматически подставляется саб-агенту при старте новых сессий, предотвращая повторное первичное исследование. Агент также может обновлять её через инструмент <code class="text-indigo-300 font-mono">memory_save</code>.
                      </p>
                      <textarea
                        id="agent-memory-box"
                        bind:value={memoryState.content}
                        rows="4"
                        placeholder="- Стек: Rust, Cargo, ONNX
- Ключевые файлы: src/main.rs, src/config.rs
- Команды: cargo test, cargo build --release
- Окружение: Docker контейнер app-container"
                        class="w-full bg-slate-900 border border-slate-700/80 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-400 resize-y min-h-[90px] leading-relaxed"
                      ></textarea>
                    </div>
                  {/if}

                  <div>
                    <span class="block text-slate-300 font-medium mb-1.5">Разрешенные скилы (Tool Permissions):</span>
                    <div class="flex flex-wrap gap-2">
                      {#each allAvailableSkills as sk}
                        {@const allowed = (newAgent.allowed_skills || []).includes(sk.id)}
                        <button
                          type="button"
                          on:click={() => toggleSkillForNewAgent(sk.id)}
                          class="px-2.5 py-1 rounded-md text-[11px] border transition flex items-center gap-1.5 cursor-pointer {allowed ? 'bg-violet-950/80 border-violet-600 text-violet-200 font-medium' : 'bg-slate-900 border-slate-800 text-slate-500 hover:text-slate-400'}"
                          title={sk.desc}
                        >
                          <span>{allowed ? '✓' : '✗'}</span>
                          <span>{sk.name}</span>
                        </button>
                      {/each}
                    </div>
                  </div>

                  <div class="pt-2 flex items-center justify-between border-t border-indigo-800/60">
                    <button
                      type="button"
                      on:click={createAgentAndAddToTeam}
                      class="px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-semibold transition text-xs shadow-md cursor-pointer flex items-center gap-1.5"
                    >
                      <span>✓</span>
                      <span>Создать роль и включить в команду</span>
                    </button>

                    <button
                      type="button"
                      on:click={() => isCreatingNewAgent = false}
                      class="px-3.5 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs transition cursor-pointer"
                    >
                      Отмена
                    </button>
                  </div>
                </div>
              {/if}

              <!-- Agents List & Skill Editing -->
              <div class="space-y-4">
                <div class="flex items-center justify-between">
                  <div>
                    <h3 class="font-bold text-sm text-slate-200 flex items-center gap-2">
                      <span>Саб-агенты этой команды ({currentTeamMembers.length})</span>
                    </h3>
                    <p class="text-[11px] text-slate-400">Настройка промпта, модели, прав вызова инструментов и управление составом</p>
                  </div>

                  <button
                    type="button"
                    on:click={startCreateNewAgent}
                    class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white text-xs font-semibold transition flex items-center gap-1.5 shadow-sm cursor-pointer"
                  >
                    <span>➕</span>
                    <span>Добавить новую роль</span>
                  </button>
                </div>

                {#each currentTeamMembers as agent}
                  <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/70 space-y-3">
                    <div class="flex items-start justify-between">
                      <div class="flex items-center gap-2">
                        <span class="font-bold text-sm text-slate-100">{agent.name}</span>
                        <span class="px-2 py-0.5 rounded text-[10px] uppercase font-semibold bg-slate-800 text-slate-300 border border-slate-700">
                          {agent.role}
                        </span>
                        {#if currentTeam.lead_agent_id === agent.id}
                          <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold bg-amber-950/90 text-amber-300 border border-amber-500/80 flex items-center gap-1 shadow-sm">
                            <span>👑</span> Главный (Root Lead)
                          </span>
                        {:else}
                          <button
                            type="button"
                            on:click={() => setAsTeamLead(agent.id)}
                            class="px-2 py-0.5 rounded text-[10px] bg-slate-800/80 hover:bg-amber-950/70 hover:text-amber-200 hover:border-amber-600/70 text-slate-400 border border-slate-700 transition flex items-center gap-1 cursor-pointer"
                            title="Сделать этого агента главным получателем входящей задачи"
                          >
                            <span>👑</span> Сделать главным
                          </button>
                        {/if}
                      </div>

                      <div class="flex items-center gap-2">
                        <!-- LLM Profile Selector for Agent -->
                        <div class="flex items-center gap-1.5">
                          <label for={`agent-profile-${agent.id}`} class="text-[10px] text-slate-400 font-medium">Профиль:</label>
                          <select
                            id={`agent-profile-${agent.id}`}
                            bind:value={agent.llm_profile_id}
                            on:change={() => onAgentProfileChange(agent)}
                            class="bg-slate-900 border border-slate-800 rounded px-2 py-1 text-[11px] text-slate-200 font-medium focus:outline-none focus:border-indigo-500 max-w-[140px] truncate cursor-pointer"
                          >
                            <option value="">(По умолчанию)</option>
                            {#each profiles as prof}
                              <option value={prof.id}>{prof.name}</option>
                            {/each}
                          </select>
                        </div>

                        <input
                          type="text"
                          bind:value={agent.model}
                          on:blur={() => saveAgent(agent)}
                          placeholder="Модель (e.g. gpt-4o)"
                          class="bg-slate-900 border border-slate-800 rounded px-2 py-1 text-[11px] font-mono text-slate-200 w-28 focus:outline-none focus:border-indigo-500"
                        />



                        <button
                          on:click={() => saveAgent(agent)}
                          class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 text-[11px] font-medium cursor-pointer"
                          title="Сохранить настройки агента"
                        >
                          Сохранить
                        </button>

                        <!-- Remove from Team Button -->
                        {#if currentTeam.member_agent_ids?.length > 1}
                          <button
                            type="button"
                            on:click={() => removeAgentFromTeam(agent.id)}
                            class="px-2 py-1 rounded bg-slate-900 hover:bg-slate-800 text-slate-400 hover:text-amber-300 text-[11px] border border-slate-800 transition cursor-pointer flex items-center gap-1"
                            title="Исключить эту роль из текущей команды"
                          >
                            <span>✕</span>
                            <span>Из команды</span>
                          </button>
                        {/if}

                        <!-- Delete Role Completely Button -->
                        {#if allAgents.length > 1}
                          <button
                            type="button"
                            on:click={() => deleteAgent(agent.id)}
                            class="p-1 rounded hover:bg-rose-950/60 text-slate-500 hover:text-rose-400 text-xs transition cursor-pointer"
                            title="Полностью удалить роль из системы"
                          >
                            🗑️
                          </button>
                        {/if}
                      </div>
                    </div>

                    <!-- Description / System Prompt -->
                    <div>
                      <label for={`agent-prompt-${agent.id}`} class="block text-slate-400 text-[11px] font-medium mb-1">System Prompt / Описание роли:</label>
                      <textarea
                        id={`agent-prompt-${agent.id}`}
                        bind:value={agent.system_prompt}
                        on:blur={() => saveAgent(agent)}
                        rows="4"
                        class="w-full bg-slate-900 border border-slate-800 rounded-lg p-3 text-slate-200 text-xs focus:outline-none focus:border-indigo-500 resize-y min-h-[120px] max-h-[600px] leading-relaxed font-sans"
                      ></textarea>
                    </div>

                    <!-- Skills Checkbox Matrix -->
                    <div>
                      <span class="block text-slate-400 text-[11px] font-medium mb-1.5">Разрешенные скилы (Tool Permissions):</span>
                      <div class="flex flex-wrap gap-2">
                        {#each allAvailableSkills as sk}
                          {@const allowed = (agent.allowed_skills || []).includes(sk.id)}
                          <button
                            type="button"
                            on:click={() => toggleSkillForAgent(agent, sk.id)}
                            class="px-2.5 py-1 rounded-md text-[11px] border transition flex items-center gap-1.5 cursor-pointer {allowed ? 'bg-violet-950/70 border-violet-700 text-violet-200 font-medium' : 'bg-slate-900 border-slate-800 text-slate-500 hover:text-slate-400'}"
                            title={sk.desc}
                          >
                            <span>{allowed ? '✓' : '✗'}</span>
                            <span>{sk.name}</span>
                          </button>
                        {/each}
                      </div>
                    </div>

                    <!-- Delegation / Callable Sub-Agents Matrix -->
                    {#if (agent.allowed_skills || []).includes('call_sub_agent')}
                      <div class="mt-3 pt-3 border-t border-slate-800/80">
                        <div class="flex items-center justify-between mb-1.5">
                          <span class="block text-indigo-300 text-[11px] font-semibold flex items-center gap-1">
                            <span>🎯</span> Разрешено вызывать саб-агентов (Delegation Targets):
                          </span>
                          <span class="text-[10px] text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">
                            {(agent.allowed_sub_agent_ids || []).length === 0 ? 'Все агенты команды' : `Выбрано: ${(agent.allowed_sub_agent_ids || []).length}`}
                          </span>
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                          {#each allAgents.filter(other => other.id !== agent.id) as other}
                            {@const canCall = (agent.allowed_sub_agent_ids || []).includes(other.id)}
                            <button
                              type="button"
                              on:click={() => toggleCallableSubAgent(agent, other.id)}
                              class="px-2.5 py-1 rounded text-[11px] border transition flex items-center gap-1.5 cursor-pointer {canCall ? 'bg-indigo-950/90 border-indigo-500 text-indigo-200 font-medium shadow-sm' : 'bg-slate-900 border-slate-800 text-slate-500 hover:text-slate-400'}"
                            >
                              <span>{canCall ? '✓' : '+'}</span>
                              <span>{other.name}</span>
                              <span class="text-[9px] opacity-70 uppercase">({other.role})</span>
                            </button>
                          {/each}
                        </div>
                      </div>
                    {/if}
                  </div>
                {/each}

                <!-- Available roles not currently in this team -->
                {#if availableNotInTeam.length > 0}
                  <div class="mt-6 pt-4 border-t border-slate-800 space-y-3">
                    <div class="flex items-center justify-between">
                      <span class="font-bold text-xs text-slate-400 flex items-center gap-1.5">
                        <span>📦</span>
                        <span>Другие доступные роли не в этой команде ({availableNotInTeam.length}):</span>
                      </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                      {#each availableNotInTeam as otherAgent}
                        <div class="p-3 rounded-xl border border-slate-800/80 bg-slate-950/40 flex items-center justify-between">
                          <div>
                            <div class="flex items-center gap-2">
                              <span class="font-semibold text-xs text-slate-300">{otherAgent.name}</span>
                              <span class="px-1.5 py-0.2 rounded text-[9px] uppercase font-mono bg-slate-900 text-slate-400 border border-slate-800">{otherAgent.role}</span>
                            </div>
                            <span class="text-[10px] text-slate-500 font-mono truncate block mt-0.5">{otherAgent.model}</span>
                          </div>

                          <div class="flex items-center gap-2">
                            <button
                              type="button"
                              on:click={() => addAgentToTeam(otherAgent.id)}
                              class="px-2.5 py-1 rounded bg-indigo-600/30 hover:bg-indigo-600/50 text-indigo-300 border border-indigo-500/40 text-[11px] font-medium transition cursor-pointer"
                              title="Включить эту роль в текущую команду"
                            >
                              + В команду
                            </button>
                            <button
                              type="button"
                              on:click={() => deleteAgent(otherAgent.id)}
                              class="p-1 rounded hover:bg-rose-950/60 text-slate-500 hover:text-rose-400 text-xs transition cursor-pointer"
                              title="Удалить роль из системы"
                            >
                              🗑️
                            </button>
                          </div>
                        </div>
                      {/each}
                    </div>
                  </div>
                {/if}
              </div>
            {/if}
          </div>

        <!-- ================= SECTION 3: LLM PROFILES ================= -->
        {:else if activeSection === 'llm'}
          <div class="space-y-6">
            <!-- Saved Profiles Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              {#each profiles as prof}
                <div class="p-3.5 rounded-xl border transition flex flex-col justify-between {activeProfileId === prof.id ? 'border-emerald-500 bg-emerald-950/20 ring-1 ring-emerald-500/50' : selectedProfile.id === prof.id ? 'border-indigo-500 bg-slate-900' : 'border-slate-800 bg-slate-950/60 hover:border-slate-700'}">
                  <div>
                    <div class="flex items-center justify-between mb-1">
                      <span class="font-bold text-xs text-slate-200 truncate">{prof.name}</span>
                      {#if activeProfileId === prof.id}
                        <span class="px-1.5 py-0.5 rounded-full bg-emerald-950 text-emerald-400 text-[9px] font-bold uppercase border border-emerald-800/80 animate-pulse">
                          Активен
                        </span>
                      {/if}
                    </div>
                    <span class="text-[10px] font-mono text-slate-400 block truncate">{prof.default_model}</span>
                    <span class="text-[9px] text-slate-500 block truncate mt-0.5">{prof.base_url}</span>
                  </div>

                  <div class="mt-3 flex items-center justify-between pt-2 border-t border-slate-800/60">
                    <button
                      on:click={() => selectedProfile = { ...prof }}
                      class="text-[10px] text-slate-400 hover:text-slate-200 font-medium cursor-pointer"
                    >
                      Редактировать
                    </button>
                    {#if activeProfileId !== prof.id}
                      <button
                        on:click={() => activateProfile(prof.id)}
                        class="px-2 py-0.5 rounded bg-emerald-600 hover:bg-emerald-500 text-white text-[10px] font-medium transition shadow-sm cursor-pointer"
                      >
                        Активировать
                      </button>
                    {/if}
                  </div>
                </div>
              {/each}
            </div>

            <!-- Profile Editor Form -->
            <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/60 space-y-4 max-w-2xl">
              <div class="flex items-center justify-between">
                <h3 class="font-bold text-sm text-slate-200">
                  {selectedProfile.id ? `Редактирование профиля: ${selectedProfile.name}` : 'Новый LLM профиль'}
                </h3>
                <button
                  on:click={startNewProfile}
                  class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium border border-slate-700 cursor-pointer"
                >
                  + Добавить профиль
                </button>
              </div>

              <div>
                <label for="profile-name-input" class="block text-slate-400 font-medium mb-1">Имя профиля:</label>
                <input id="profile-name-input" type="text" bind:value={selectedProfile.name} placeholder="e.g. DeepSeek Production" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 focus:outline-none focus:border-indigo-500 font-medium" />
              </div>

              <div>
                <label for="profile-url-input" class="block text-slate-400 font-medium mb-1">Base URL (OpenAI-совместимый эндпоинт):</label>
                <input id="profile-url-input" type="text" bind:value={selectedProfile.base_url} placeholder="https://api.openai.com/v1" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 font-mono focus:outline-none focus:border-indigo-500" />
              </div>

              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label for="profile-key-input" class="block text-slate-400 font-medium mb-1">API Key:</label>
                  <input id="profile-key-input" type="password" bind:value={selectedProfile.api_key} placeholder="sk-..." class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 font-mono focus:outline-none focus:border-indigo-500" />
                </div>
                <div>
                  <label for="profile-model-input" class="block text-slate-400 font-medium mb-1">Модель по умолчанию:</label>
                  <input id="profile-model-input" type="text" bind:value={selectedProfile.default_model} placeholder="deepseek-chat или gpt-4o" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 font-mono focus:outline-none focus:border-indigo-500" />
                </div>
              </div>

              <div class="flex items-center justify-between pt-2">
                <div class="flex items-center gap-2">
                  <button on:click={saveProfile} class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-medium transition text-xs shadow cursor-pointer">
                    Сохранить профиль
                  </button>
                  {#if activeProfileId !== selectedProfile.id}
                    <button on:click={() => activateProfile(selectedProfile.id)} class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-medium transition text-xs shadow cursor-pointer">
                      Сохранить и активировать
                    </button>
                  {/if}
                </div>

                {#if profiles.length > 1 && selectedProfile.id}
                  <button on:click={() => deleteProfile(selectedProfile.id)} class="text-rose-400 hover:text-rose-300 text-xs transition cursor-pointer">
                    Удалить профиль
                  </button>
                {/if}
              </div>
            </div>
          </div>

        <!-- ================= SECTION 4: GENERAL & EXECUTION SETTINGS ================= -->
        {:else if activeSection === 'general'}
          <div class="space-y-6">
            <!-- Header Banner -->
            <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/70 flex items-start justify-between">
              <div>
                <h3 class="font-bold text-sm text-slate-100 flex items-center gap-2">
                  <span>⚙️</span>
                  <span>Параметры выполнения, лимиты и защита от сбоев</span>
                </h3>
                <p class="text-[11px] text-slate-400 mt-1">
                  Глобальная конфигурация ReAct-циклов саб-агентов, защита от бесконечного зацикливания и параметры повтора запросов к LLM API.
                </p>
              </div>
              <button
                type="button"
                on:click={saveSystemSettings}
                class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-semibold transition text-xs shadow-md cursor-pointer flex items-center gap-1.5 shrink-0"
              >
                <span>💾</span>
                <span>Сохранить параметры</span>
              </button>
            </div>

            <!-- Card 0: Global System Prompt for all projects -->
            <div class="p-4 rounded-xl border border-indigo-900/60 bg-indigo-950/25 space-y-3 shadow-lg">
              <div class="flex items-center justify-between pb-2 border-b border-indigo-900/40">
                <div class="flex items-center gap-2">
                  <span class="text-base">🌐</span>
                  <div>
                    <h4 class="font-bold text-xs text-indigo-200">Глобальный системный промпт (общий для всех проектов)</h4>
                    <p class="text-[10px] text-slate-400">Глобальные правила, стандарты качества, ограничения безопасности и стиль поведения для всех проектов системы</p>
                  </div>
                </div>
              </div>

              <div>
                <label for="global-prompt-input" class="block text-slate-300 font-medium mb-1 text-[11px]">
                  Инструкции, применяемые ко всем проектам и сессиям:
                </label>
                <textarea
                  id="global-prompt-input"
                  bind:value={systemSettings.global_system_prompt}
                  on:blur={handleAutoSaveSystemSettings}
                  rows="5"
                  placeholder="Например: Всегда пишите чистый код с комментариями на русском языке, проверяйте граничные условия, строго соблюдайте архитектурные паттерны, не удаляйте важные файлы без подтверждения человека..."
                  class="w-full bg-slate-950 border border-indigo-800/60 rounded-lg p-3 text-slate-100 text-xs focus:outline-none focus:border-indigo-400 resize-y min-h-[140px] max-h-[700px] leading-relaxed font-sans"
                ></textarea>
                <p class="text-[10px] text-slate-500 mt-1">
                  * Этот промпт автоматически добавляется в самое начало системных инструкций всех саб-агентов независимо от выбранного проекта.
                </p>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <!-- Card 1: Subagent & Root Agent Step Limits -->
              <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/60 space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-slate-800">
                  <span class="text-base">🪜</span>
                  <div>
                    <h4 class="font-bold text-xs text-slate-200">Лимиты шагов агентов (ReAct Step Limits)</h4>
                    <p class="text-[10px] text-slate-400">Ограничение количества итераций «Мысль → Инструмент → Ответ»</p>
                  </div>
                </div>

                <div>
                  <label for="subagent-steps-input" class="block text-slate-300 font-medium mb-1 flex items-center justify-between">
                    <span class="text-indigo-400 font-semibold">Лимит шагов сабагента:</span>
                    <span class="text-[11px] font-mono text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">{systemSettings.subagent_max_steps} шагов</span>
                  </label>
                  <input
                    id="subagent-steps-input"
                    type="number"
                    min="1"
                    max="1000"
                    bind:value={systemSettings.subagent_max_steps}
                    on:blur={handleAutoSaveSystemSettings}
                    class="w-full bg-slate-950 border border-indigo-700/60 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-400 ring-1 ring-indigo-500/20"
                  />
                  <p class="text-[10px] text-slate-500 mt-1">
                    Максимальное число шагов выполнения для вызываемых саб-агентов (backend, frontend, qa, techlead). Предотвращает перерасход токенов на узких подзадачах.
                  </p>
                </div>

                <!-- Setting 1: Context Window Compaction Threshold -->
                <div>
                  <label for="subagent-context-input" class="block text-slate-300 font-medium mb-1 flex items-center justify-between">
                    <span class="text-indigo-400 font-semibold">Лимит контекстного окна (Context Window Limit):</span>
                    <span class="text-[11px] font-mono text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">{Number(systemSettings.subagent_context_tokens || 65536).toLocaleString()} токенов</span>
                  </label>
                  <input
                    id="subagent-context-input"
                    type="number"
                    min="4096"
                    step="4096"
                    max="1000000"
                    bind:value={systemSettings.subagent_context_tokens}
                    on:blur={handleAutoSaveSystemSettings}
                    class="w-full bg-slate-950 border border-indigo-700/60 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-400 ring-1 ring-indigo-500/20"
                  />
                  <p class="text-[10px] text-slate-500 mt-1">
                    Размер истории запроса саб-агента, по превышению которого выполняется интеллектуальная компактизация контекста без потери файлов и статусов.
                  </p>
                </div>

                <!-- Setting 2: Total Execution Budget Limit -->
                <div>
                  <label for="subagent-budget-input" class="block text-slate-300 font-medium mb-1 flex items-center justify-between">
                    <span class="text-indigo-400 font-semibold">Максимальный бюджет токенов на выполнение (Total Budget):</span>
                    <span class="text-[11px] font-mono text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">{Number(systemSettings.subagent_max_tokens || 100000).toLocaleString()} токенов</span>
                  </label>
                  <input
                    id="subagent-budget-input"
                    type="number"
                    min="5000"
                    step="10000"
                    max="2000000"
                    bind:value={systemSettings.subagent_max_tokens}
                    on:blur={handleAutoSaveSystemSettings}
                    class="w-full bg-slate-950 border border-indigo-700/60 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-400 ring-1 ring-indigo-500/20"
                  />
                  <p class="text-[10px] text-slate-500 mt-1">
                    Суммарный лимит расхода токенов (Prompt + Completion) на все шаги работы саб-агента. Предотвращает неконтролируемый перерасход бюджета API.
                  </p>
                </div>

                <div>
                  <label for="root-steps-input" class="block text-slate-300 font-medium mb-1 flex items-center justify-between">
                    <span>Лимит шагов главного агента:</span>
                    <span class="text-[11px] font-mono text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">{systemSettings.root_max_steps} шагов</span>
                  </label>
                  <input
                    id="root-steps-input"
                    type="number"
                    min="1"
                    max="100"
                    bind:value={systemSettings.root_max_steps}
                    on:blur={handleAutoSaveSystemSettings}
                    class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-500"
                  />
                  <p class="text-[10px] text-slate-500 mt-1">
                    Максимальное число шагов для корневого агента сессии (Project Manager).
                  </p>
                </div>
              </div>

              <!-- Card 2: Anti-Loop Protection -->
              <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/60 space-y-4">
                <div class="flex items-center gap-2 pb-2 border-b border-slate-800">
                  <span class="text-base">🔄</span>
                  <div>
                    <h4 class="font-bold text-xs text-slate-200">Защита от зацикливания (Anti-Loop Protection)</h4>
                    <p class="text-[10px] text-slate-400">Предотвращение повторных вызовов одинаковых инструментов</p>
                  </div>
                </div>

                <div class="flex items-center justify-between bg-slate-900/80 p-3 rounded-lg border border-slate-800">
                  <div>
                    <span class="font-medium text-slate-200 block text-xs">Включить защиту от зацикливания</span>
                    <span class="text-[10px] text-slate-400">Останавливать агента при выявлении циклов повторений</span>
                  </div>
                  <label class="relative inline-flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      bind:checked={systemSettings.loop_protection_enabled}
                      on:change={handleAutoSaveSystemSettings}
                      class="sr-only peer"
                    />
                    <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                  </label>
                </div>

                <div>
                  <label for="loop-threshold-input" class="block text-slate-300 font-medium mb-1 flex items-center justify-between">
                    <span>Порог повторений для детекции цикла:</span>
                    <span class="text-[11px] font-mono text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">{systemSettings.loop_detection_threshold} повтора</span>
                  </label>
                  <input
                    id="loop-threshold-input"
                    type="number"
                    min="2"
                    max="10"
                    bind:value={systemSettings.loop_detection_threshold}
                    on:blur={handleAutoSaveSystemSettings}
                    disabled={!systemSettings.loop_protection_enabled}
                    class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-500 disabled:opacity-50"
                  />
                  <p class="text-[10px] text-slate-500 mt-1">
                    При {systemSettings.loop_detection_threshold - 1} одинаковых вызовах агенту высылается предупреждение; при достижении {systemSettings.loop_detection_threshold} повторов шаг принудительно прерывается.
                  </p>
                </div>
              </div>
            </div>

            <!-- Card 3: LLM API Error Retry & Pause Backoff -->
            <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/60 space-y-4">
              <div class="flex items-center gap-2 pb-2 border-b border-slate-800">
                <span class="text-base">🔁</span>
                <div>
                  <h4 class="font-bold text-xs text-slate-200">Повтор запросов к LLM API (Retry & Pause Mechanism)</h4>
                  <p class="text-[10px] text-slate-400">Автоматический повтор запроса при сбоях сети, таймаутах или ошибках 429 / 5xx от нейросети</p>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label for="llm-retries-input" class="block text-slate-300 font-medium mb-1 flex items-center justify-between">
                    <span>Количество повторов запроса (Max Retries):</span>
                    <span class="text-[11px] font-mono text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">{systemSettings.llm_max_retries} попытки</span>
                  </label>
                  <input
                    id="llm-retries-input"
                    type="number"
                    min="0"
                    max="10"
                    bind:value={systemSettings.llm_max_retries}
                    on:blur={handleAutoSaveSystemSettings}
                    class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-500"
                  />
                  <p class="text-[10px] text-slate-500 mt-1">
                    Сколько раз перезапрашивать ответ у LLM API при получении ошибки (0 — отключить повторы).
                  </p>
                </div>

                <div>
                  <label for="llm-delay-input" class="block text-slate-300 font-medium mb-1 flex items-center justify-between">
                    <span>Пауза между повторами (Retry Delay):</span>
                    <span class="text-[11px] font-mono text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">{systemSettings.llm_retry_delay_sec} сек.</span>
                  </label>
                  <input
                    id="llm-delay-input"
                    type="number"
                    min="1"
                    max="60"
                    bind:value={systemSettings.llm_retry_delay_sec}
                    on:blur={handleAutoSaveSystemSettings}
                    class="w-full bg-slate-950 border border-slate-800 rounded-lg p-2.5 text-slate-100 font-mono text-xs focus:outline-none focus:border-indigo-500"
                  />
                  <p class="text-[10px] text-slate-500 mt-1">
                    Длительность паузы в секундах перед следующей попыткой запроса к провайдеру LLM.
                  </p>
                </div>
              </div>
            </div>

            <!-- Bottom Save Bar -->
            <div class="flex items-center justify-end gap-3 pt-2">
              <button
                type="button"
                on:click={saveSystemSettings}
                class="px-5 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-semibold transition text-xs shadow-md cursor-pointer flex items-center gap-1.5"
              >
                <span>💾</span>
                <span>Сохранить параметры системы</span>
              </button>
            </div>
          </div>
        {/if}
      </div>

      <!-- Modal Footer -->
      <div class="px-6 py-4 border-t border-slate-800 flex items-center justify-between bg-slate-950/40">
        <div>
          {#if saveStatus}
            <span class="text-xs font-semibold text-emerald-400 animate-pulse">{saveStatus}</span>
          {/if}
        </div>
        <button on:click={handleCloseModal} class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium transition text-xs cursor-pointer">
          Закрыть
        </button>
      </div>
    </div>
  </div>

  <!-- Folder Picker Modal -->
  <FolderPickerModal
    isOpen={isFolderPickerOpen}
    initialPath={currentProject.workspace_path || '/workspace'}
    onSelect={handleFolderSelected}
    onClose={() => isFolderPickerOpen = false}
  />
{/if}
