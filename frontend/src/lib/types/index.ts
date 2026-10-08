export type NodeStatus = 'pending' | 'active' | 'calling_tool' | 'waiting_human' | 'completed' | 'failed';

export interface TodoItem {
  content: string;
  status: 'pending' | 'in_progress' | 'completed';
}

export interface ToolCallLog {
  id: string;
  name: string;
  args: Record<string, any>;
  status: 'running' | 'ok' | 'fail';
  output?: string;
  error?: string;
  durationMs?: number;
  step?: number;
}

export interface DialogMessage {
  role: 'system' | 'user' | 'assistant' | 'tool' | 'thinking';
  text: string;
  timestamp: string;
  step?: number;
  name?: string;
  call_id?: string;
  args?: Record<string, any>;
  status?: 'running' | 'ok' | 'fail';
  output?: string;
  error?: string;
  durationMs?: number;
  is_compaction?: boolean;
  before_tokens?: number;
  after_tokens?: number;
  summary?: string;
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
  contextTokens?: number;
  durationMs: number;
  llm_profile_id?: string;
  startedAt: string;
  dialog: DialogMessage[];
  toolCalls: ToolCallLog[];
  todos?: TodoItem[];
  expectedOutcome?: string;
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
  project_prompt?: string;
}

export interface SystemSettings {
  subagent_max_steps: number;
  root_max_steps: number;
  subagent_max_tokens: number;
  subagent_context_tokens: number;
  loop_protection_enabled: boolean;
  loop_detection_threshold: number;
  llm_max_retries: number;
  llm_retry_delay_sec: number;
  global_system_prompt?: string;
}
