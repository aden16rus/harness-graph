import { writable, derived } from 'svelte/store';

export interface ProjectData {
  id: string;
  name: string;
  workspace_path: string;
  stack: string;
  default_container?: string;
  guidelines_file?: string;
  default_team_id?: string;
  created_at?: string;
}

export const projectsList = writable<ProjectData[]>([]);
export const activeProjectId = writable<string>(localStorage.getItem('active_project_id') || 'proj_default');

export const activeProject = derived(
  [projectsList, activeProjectId],
  ([$list, $id]) => {
    return $list.find((p) => p.id === $id) || ($list.length > 0 ? $list[0] : null);
  }
);

export async function fetchProjects(): Promise<ProjectData[]> {
  try {
    const res = await fetch('/api/projects');
    if (res.ok) {
      const data: ProjectData[] = await res.json();
      projectsList.set(data);
      if (data.length > 0) {
        activeProjectId.update((curr) => {
          if (!data.some((p) => p.id === curr)) {
            const firstId = data[0].id;
            localStorage.setItem('active_project_id', firstId);
            return firstId;
          }
          return curr;
        });
      }
      return data;
    }
  } catch (e) {
    console.warn('Failed to fetch projects:', e);
  }
  return [];
}

export function setActiveProject(projectId: string) {
  activeProjectId.set(projectId);
  localStorage.setItem('active_project_id', projectId);
}

export async function saveProject(project: Partial<ProjectData>): Promise<ProjectData | null> {
  try {
    const res = await fetch('/api/projects', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(project),
    });
    if (res.ok) {
      const json = await res.json();
      await fetchProjects();
      if (json.project?.id) {
        setActiveProject(json.project.id);
      }
      return json.project || null;
    }
  } catch (e) {
    console.error('Failed to save project:', e);
  }
  return null;
}

export async function deleteProject(id: string): Promise<boolean> {
  try {
    const res = await fetch(`/api/projects?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
    });
    if (res.ok) {
      const updated = await fetchProjects();
      activeProjectId.update((curr) => {
        if (curr === id && updated.length > 0) {
          const nextId = updated[0].id;
          localStorage.setItem('active_project_id', nextId);
          return nextId;
        }
        return curr;
      });
      return true;
    }
  } catch (e) {
    console.error('Failed to delete project:', e);
  }
  return false;
}