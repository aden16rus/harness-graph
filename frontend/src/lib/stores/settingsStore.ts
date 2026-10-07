import { writable } from 'svelte/store';
import type { SystemSettings } from '../types';

export const defaultSystemSettings: SystemSettings = {
  subagent_max_steps: 200,
  root_max_steps: 25,
  subagent_max_tokens: 100000,
  subagent_context_tokens: 65536,
  loop_protection_enabled: true,
  loop_detection_threshold: 5,
  llm_max_retries: 3,
  llm_retry_delay_sec: 3,
  global_system_prompt: '',
};

export const systemSettingsStore = writable<SystemSettings>(defaultSystemSettings);
export const isSettingsLoading = writable<boolean>(false);

export async function fetchSystemSettings(): Promise<SystemSettings> {
  try {
    isSettingsLoading.set(true);
    const res = await fetch('/api/settings');
    if (res.ok) {
      const data = await res.json();
      const loaded: SystemSettings = {
        subagent_max_steps: Number(data.subagent_max_steps ?? defaultSystemSettings.subagent_max_steps),
        root_max_steps: Number(data.root_max_steps ?? defaultSystemSettings.root_max_steps),
        subagent_max_tokens: Number(data.subagent_max_tokens ?? defaultSystemSettings.subagent_max_tokens),
        subagent_context_tokens: Number(data.subagent_context_tokens ?? defaultSystemSettings.subagent_context_tokens),
        loop_protection_enabled: Boolean(data.loop_protection_enabled ?? defaultSystemSettings.loop_protection_enabled),
        loop_detection_threshold: Number(data.loop_detection_threshold ?? defaultSystemSettings.loop_detection_threshold),
        llm_max_retries: Number(data.llm_max_retries ?? defaultSystemSettings.llm_max_retries),
        llm_retry_delay_sec: Number(data.llm_retry_delay_sec ?? defaultSystemSettings.llm_retry_delay_sec),
        global_system_prompt: String(data.global_system_prompt ?? defaultSystemSettings.global_system_prompt),
      };
      systemSettingsStore.set(loaded);
      return loaded;
    }
  } catch (e) {
    console.warn('[SettingsStore] Failed to fetch system settings:', e);
  } finally {
    isSettingsLoading.set(false);
  }
  return defaultSystemSettings;
}

export async function saveSystemSettings(settings: Partial<SystemSettings>): Promise<boolean> {
  try {
    const res = await fetch('/api/settings', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(settings),
    });
    if (res.ok) {
      const json = await res.json();
      if (json.settings) {
        systemSettingsStore.set({
          subagent_max_steps: Number(json.settings.subagent_max_steps),
          root_max_steps: Number(json.settings.root_max_steps),
          subagent_max_tokens: Number(json.settings.subagent_max_tokens),
          subagent_context_tokens: Number(json.settings.subagent_context_tokens ?? 65536),
          loop_protection_enabled: Boolean(json.settings.loop_protection_enabled),
          loop_detection_threshold: Number(json.settings.loop_detection_threshold),
          llm_max_retries: Number(json.settings.llm_max_retries),
          llm_retry_delay_sec: Number(json.settings.llm_retry_delay_sec),
          global_system_prompt: String(json.settings.global_system_prompt || ''),
        });
      }
      return true;
    }
  } catch (e) {
    console.error('[SettingsStore] Failed to save system settings:', e);
  }
  return false;
}
