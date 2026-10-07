export type NodeStatus = 'pending' | 'active' | 'calling_tool' | 'waiting_human' | 'completed' | 'failed';

export interface ToolCallLog {
  id: string;
  name: string;
  args: Record<string, any>;
  status: 'running' | 'ok' | 'fail';
  output?: string;
  error?: string;
  durationMs?: number;
}

export interface DialogMessage {
  role: 'system' | 'user' | 'assistant' | 'tool' | 'thinking';
  text: string;
  timestamp: string;
  name?: string;
  call_id?: string;
  args?: Record<string, any>;
  status?: 'running' | 'ok' | 'fail';
  output?: string;
  error?: string;
  durationMs?: number;
}

export interface AgentNodeData {
  id: string;
  parentId?: string;
  agentId: string;
  agentName: string;
  role: string;
  status: NodeStatus;
  depth: number;
  inputPrompt: string;
  outputResult: string;
  activeTool?: string;
  promptTokens: number;
  completionTokens: number;
  durationMs: number;
  llm_profile_id?: string;
  startedAt: string;
  dialog: DialogMessage[];
  toolCalls: ToolCallLog[];
  humanPrompt?: {
    question: string;
    options?: string[];
  };
}

export interface SessionMetrics {
  sessionId: string;
  status: 'idle' | 'running' | 'completed' | 'failed';
  totalPromptTokens: number;
  totalCompletionTokens: number;
  totalDurationMs: number;
  activeNodes: number;
  completedNodes: number;
}

export interface ProjectSettings {
  name: string;
  workspacePath: string;
  stack: string;
  defaultContainer: string;
  guidelinesFile: string;
  llmBaseUrl: string;
  llmApiKey: string;
  defaultModel: string;
}

export interface SystemSettings {
  subagent_max_steps: number;
  root_max_steps: number;
  loop_protection_enabled: boolean;
  loop_detection_threshold: number;
  llm_max_retries: number;
  llm_retry_delay_sec: number;
}
