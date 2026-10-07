import { writable } from 'svelte/store';
import type { AgentNodeData, SessionMetrics, ToolCallLog } from '../types';

export const sessionStore = writable<SessionMetrics>({
  sessionId: 'sess_default',
  status: 'idle',
  totalPromptTokens: 0,
  totalCompletionTokens: 0,
  totalDurationMs: 0,
  activeNodes: 0,
  completedNodes: 0,
});

export const nodesStore = writable<Map<string, AgentNodeData>>(new Map());
export const selectedNodeId = writable<string | null>(null);
export const wsConnected = writable<boolean>(false);

class WebSocketManager {
  private socket: WebSocket | null = null;
  private currentSessionId = 'sess_default';
  private reconnectTimer: any = null;

  public connect(sessionId: string) {
    this.currentSessionId = sessionId;
    if (this.socket) {
      this.socket.close();
    }

    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const host = window.location.host;
    const url = `${protocol}//${host}/ws?session_id=${encodeURIComponent(sessionId)}`;

    try {
      this.socket = new WebSocket(url);

      this.socket.onopen = () => {
        wsConnected.set(true);
        console.log('[WS] Connected to Harness Engine:', url);
      };

      this.socket.onclose = () => {
        wsConnected.set(false);
        console.log('[WS] Connection closed, reconnecting in 2s...');
        clearTimeout(this.reconnectTimer);
        this.reconnectTimer = setTimeout(() => this.connect(this.currentSessionId), 2000);
      };

      this.socket.onerror = (err) => {
        console.warn('[WS] Socket error:', err);
      };

      this.socket.onmessage = (event) => {
        try {
          const lines = event.data.split('\n');
          for (const line of lines) {
            if (!line.trim()) continue;
            const parsed = JSON.parse(line);
            this.handleEvent(parsed);
          }
        } catch (e) {
          console.error('[WS] Failed to parse message:', e, event.data);
        }
      };
    } catch (e) {
      console.error('[WS] Connect error:', e);
    }
  }

  public sendHumanAnswer(nodeId: string, answer: string) {
    if (this.socket && this.socket.readyState === WebSocket.OPEN) {
      this.socket.send(JSON.stringify({
        action: 'human.answer',
        node_id: nodeId,
        answer: answer,
        session_id: this.currentSessionId,
      }));
    } else {
      // Fallback to HTTP POST
      fetch('/api/human/answer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ node_id: nodeId, answer }),
      }).catch(console.error);
    }
  }

  private handleEvent(ev: any) {
    const { event, node_id, data, timestamp } = ev;
    const now = timestamp || new Date().toISOString();

    nodesStore.update((nodes) => {
      const updated = new Map(nodes);

      switch (event) {
        case 'session.started': {
          sessionStore.update(s => ({
            ...s,
            sessionId: ev.session_id || s.sessionId,
            status: 'running',
            activeNodes: 1,
          }));
          break;
        }

        case 'session.completed': {
          sessionStore.update(s => ({
            ...s,
            status: 'completed',
          }));
          break;
        }

        case 'session.failed': {
          sessionStore.update(s => ({
            ...s,
            status: 'failed',
          }));
          break;
        }

        case 'graph.node_created': {
          if (ev.session_id && ev.session_id !== this.currentSessionId) {
            this.currentSessionId = ev.session_id;
            sessionStore.update(s => ({ ...s, sessionId: ev.session_id, status: 'running' }));
          }
          const initialDialog: any[] = [];
          if (data.system_prompt) {
            initialDialog.push({
              role: 'system',
              text: data.system_prompt,
              timestamp: now,
            });
          }
          if (data.input_prompt) {
            initialDialog.push({
              role: 'user',
              text: data.input_prompt,
              timestamp: now,
            });
          }

          const newNode: AgentNodeData = {
            id: data.id || node_id,
            parentId: data.parent_node_id,
            agentId: data.agent_id || 'unknown',
            agentName: data.agent_name || 'Agent',
            role: data.role || 'general',
            status: data.status || 'active',
            depth: data.depth || 0,
            inputPrompt: data.input_prompt || '',
            outputResult: '',
            promptTokens: 0,
            completionTokens: 0,
            durationMs: 0,
            startedAt: data.started_at || now,
            dialog: initialDialog,
            toolCalls: [],
          };
          updated.set(newNode.id, newNode);
          selectedNodeId.update(current => current === null ? newNode.id : current);
          break;
        }

        case 'graph.node_stream': {
          const node = updated.get(node_id);
          if (node) {
            if (data.reasoning_delta) {
              const lastMsg = node.dialog[node.dialog.length - 1];
              if (lastMsg && lastMsg.role === 'thinking') {
                lastMsg.text += data.reasoning_delta;
              } else {
                node.dialog.push({
                  role: 'thinking',
                  text: data.reasoning_delta,
                  timestamp: now,
                });
              }
            } else if (data.delta) {
              const lastMsg = node.dialog[node.dialog.length - 1];
              if (lastMsg && lastMsg.role === 'assistant') {
                lastMsg.text += data.delta;
              } else {
                node.dialog.push({
                  role: 'assistant',
                  text: data.delta,
                  timestamp: now,
                });
              }
            }
          }
          break;
        }

        case 'graph.node_reasoning': {
          const node = updated.get(node_id);
          if (node && data.text) {
            const lastMsg = node.dialog[node.dialog.length - 1];
            if (!lastMsg || lastMsg.role !== 'thinking' || lastMsg.text !== data.text) {
              node.dialog.push({
                role: 'thinking',
                text: data.text,
                timestamp: now,
              });
            }
          }
          break;
        }

        case 'graph.tool_call_started': {
          const node = updated.get(node_id);
          if (node) {
            node.status = 'calling_tool';
            node.activeTool = data.tool;
            const callId = data.call_id || String(Date.now());
            const newTc: ToolCallLog = {
              id: callId,
              name: data.tool,
              args: data.arguments || {},
              status: 'running',
            };
            node.toolCalls.push(newTc);

            node.dialog.push({
              role: 'tool',
              name: data.tool,
              call_id: callId,
              args: data.arguments || {},
              status: 'running',
              text: '',
              timestamp: now,
            });
          }
          break;
        }

        case 'graph.tool_call_finished': {
          const node = updated.get(node_id);
          if (node) {
            node.status = 'active';
            node.activeTool = undefined;
            const tc = node.toolCalls.find(t => t.id === data.call_id) || node.toolCalls[node.toolCalls.length - 1];
            if (tc) {
              tc.status = data.success ? 'ok' : 'fail';
              tc.output = data.output;
              tc.error = data.error;
              tc.durationMs = data.duration_ms;
            }

            const dialogItem = [...node.dialog].reverse().find(d => d.role === 'tool' && (d.call_id === data.call_id || (d.name === data.tool && d.status === 'running')));
            if (dialogItem) {
              dialogItem.status = data.success ? 'ok' : 'fail';
              dialogItem.output = data.output;
              dialogItem.error = data.error;
              dialogItem.durationMs = data.duration_ms;
              dialogItem.text = data.success ? (data.output || 'OK') : `ERROR: ${data.error}`;
            } else {
              node.dialog.push({
                role: 'tool',
                name: data.tool,
                call_id: data.call_id,
                args: data.arguments,
                status: data.success ? 'ok' : 'fail',
                output: data.output,
                error: data.error,
                durationMs: data.duration_ms,
                text: data.success ? (data.output || 'OK') : `ERROR: ${data.error}`,
                timestamp: now,
              });
            }
          }
          break;
        }

        case 'graph.loop_detected': {
          const node = updated.get(node_id);
          if (node) {
            node.dialog.push({
              role: 'system',
              text: `🛑 Защита от зацикливания: ${data.reason || 'Обнаружен цикл'}`,
              timestamp: now,
            });
          }
          break;
        }

        case 'graph.llm_retry': {
          const node = updated.get(node_id);
          if (node) {
            node.dialog.push({
              role: 'system',
              text: `🔁 Повтор запроса к LLM API (${data.attempt}/${data.max_retries}) через ${data.delay_sec} сек... (${data.reason || 'ошибка сети или квоты'})`,
              timestamp: now,
            });
          }
          break;
        }

        case 'graph.human_required': {
          const node = updated.get(node_id);
          if (node) {
            node.status = 'waiting_human';
            node.humanPrompt = {
              question: data.question,
              options: data.options || [],
            };
            selectedNodeId.set(node_id);
          }
          break;
        }

        case 'graph.human_answered': {
          const node = updated.get(node_id);
          if (node) {
            node.status = 'active';
            node.humanPrompt = undefined;
            node.dialog.push({
              role: 'user',
              text: `[Human Answer]: ${data.answer}`,
              timestamp: now,
            });
          }
          break;
        }

        case 'graph.node_completed': {
          const node = updated.get(node_id);
          if (node) {
            node.status = 'completed';
            node.outputResult = data.output_result || '';
            node.promptTokens = data.prompt_tokens || node.promptTokens;
            node.completionTokens = data.completion_tokens || node.completionTokens;
            node.durationMs = data.duration_ms || node.durationMs;
            node.activeTool = undefined;
          }
          break;
        }

        case 'graph.node_failed': {
          const node = updated.get(node_id);
          if (node) {
            node.status = 'failed';
            node.outputResult = data.error || 'Failed';
            node.durationMs = data.duration_ms || node.durationMs;
            node.activeTool = undefined;
          }
          break;
        }
      }

      return updated;
    });

    // Update aggregate session metrics
    nodesStore.subscribe((nodes) => {
      let pTokens = 0;
      let cTokens = 0;
      let active = 0;
      let completed = 0;

      for (const node of nodes.values()) {
        pTokens += node.promptTokens;
        cTokens += node.completionTokens;
        if (node.status === 'completed') completed++;
        if (node.status === 'active' || node.status === 'calling_tool' || node.status === 'waiting_human') active++;
      }

      sessionStore.update(s => ({
        ...s,
        totalPromptTokens: pTokens,
        totalCompletionTokens: cTokens,
        activeNodes: active,
        completedNodes: completed,
      }));
    })();
  }
}

export const wsManager = new WebSocketManager();

export function clearGraph() {
  nodesStore.set(new Map());
  selectedNodeId.set(null);
  sessionStore.set({
    sessionId: 'sess_default',
    status: 'idle',
    totalPromptTokens: 0,
    totalCompletionTokens: 0,
    totalDurationMs: 0,
    activeNodes: 0,
    completedNodes: 0,
  });
}

export async function stopCurrentSession(): Promise<boolean> {
  try {
    let sessId = 'sess_default';
    sessionStore.subscribe(s => sessId = s.sessionId)();
    const res = await fetch('/api/session/stop', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ session_id: sessId }),
    });
    sessionStore.update(s => ({ ...s, status: 'failed' }));
    return res.ok;
  } catch (e) {
    console.error('Failed to stop session:', e);
    return false;
  }
}

export async function loadSessionGraph(sessionId: string): Promise<boolean> {
  try {
    const res = await fetch(`/api/session/graph?id=${encodeURIComponent(sessionId)}`);
    if (!res.ok) return false;
    const data = await res.json();
    if (!data.session) return false;

    // Update aggregate session store
    sessionStore.set({
      sessionId: data.session.id,
      status: (data.session.status as any) || 'completed',
      totalPromptTokens: data.session.total_prompt_tokens || 0,
      totalCompletionTokens: data.session.total_completion_tokens || 0,
      totalDurationMs: data.session.total_duration_ms || 0,
      activeNodes: 0,
      completedNodes: data.nodes?.length || 0,
    });

    // Populate nodesStore
    const newMap = new Map<string, AgentNodeData>();
    for (const node of data.nodes || []) {
      newMap.set(node.id, node);
    }
    nodesStore.set(newMap);

    if (data.nodes?.length > 0) {
      selectedNodeId.set(data.nodes[0].id);
    }
    return true;
  } catch (e) {
    console.error('Failed to load session graph:', e);
    return false;
  }
}
