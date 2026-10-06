package e2e

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"
	"github.com/harness/agent/go-engine/internal/llm"
	"github.com/harness/agent/go-engine/internal/logger"
	"github.com/harness/agent/go-engine/internal/runner"
	"github.com/harness/agent/go-engine/internal/ws"
)

// TestE2EMultiAgentPipelineAndEvents tests the complete multi-agent pipeline
// with WebSocket streaming, NDJSON logging, and tool execution.
func TestE2EMultiAgentPipelineAndEvents(t *testing.T) {
	tempDir, err := os.MkdirTemp("", "harness_e2e_*")
	if err != nil {
		t.Fatalf("failed to create temp dir: %v", err)
	}
	defer os.RemoveAll(tempDir)

	dataDir := filepath.Join(tempDir, "data")
	logDir := filepath.Join(dataDir, "logs")
	workspaceDir := filepath.Join(tempDir, "workspace")
	_ = os.MkdirAll(workspaceDir, 0755)

	// 1. Initialize Logger
	ndjson, err := logger.NewNDJSONLogger(logDir, 100)
	if err != nil {
		t.Fatalf("failed to initialize logger: %v", err)
	}
	defer ndjson.Close()

	// 2. Initialize Runner
	localRunner := runner.NewLocalRunner(workspaceDir)

	// 3. Mock LLM Server responding with SSE chunks
	mockLLM := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		w.WriteHeader(http.StatusOK)

		flusher, ok := w.(http.Flusher)
		if !ok {
			t.Fatal("expected flusher")
		}

		// Stream reasoning chunk
		chunk1 := map[string]any{
			"id": "chatcmpl-1",
			"choices": []map[string]any{
				{
					"delta": map[string]any{"content": "Decomposing discount calculator task..."},
				},
			},
		}
		c1Bytes, _ := json.Marshal(chunk1)
		fmt.Fprintf(w, "data: %s\n\n", string(c1Bytes))
		flusher.Flush()

		// Stream tool call chunk
		callIdx := 0
		chunk2 := map[string]any{
			"id": "chatcmpl-1",
			"choices": []map[string]any{
				{
					"delta": map[string]any{
						"tool_calls": []map[string]any{
							{
								"index": &callIdx,
								"id":    "call_coder_1",
								"type":  "function",
								"function": map[string]any{
									"name":      "call_sub_agent",
									"arguments": `{"agent_role":"coder","task":"Implement DiscountCalculator"}`,
								},
							},
						},
					},
				},
			},
			"usage": map[string]any{
				"prompt_tokens":     120,
				"completion_tokens": 45,
				"total_tokens":      165,
			},
		}
		c2Bytes, _ := json.Marshal(chunk2)
		fmt.Fprintf(w, "data: %s\n\n", string(c2Bytes))
		flusher.Flush()

		fmt.Fprintf(w, "data: [DONE]\n\n")
		flusher.Flush()
	}))
	defer mockLLM.Close()

	llmClient := llm.NewClient(mockLLM.URL, "test-key")

	// 4. WebSocket Hub
	wsHub := ws.NewHub()
	go wsHub.Run()

	wsServer := httptest.NewServer(http.HandlerFunc(wsHub.HandleWebSocket))
	defer wsServer.Close()

	// 5. Connect WebSocket client
	wsURL := "ws" + strings.TrimPrefix(wsServer.URL, "http") + "/ws?session_id=sess_e2e_1"
	dialer := websocket.Dialer{}
	wsConn, _, err := dialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("failed to connect ws client: %v", err)
	}
	defer wsConn.Close()

	// Collect WS events in background
	receivedEvents := make(chan ws.Event, 50)
	go func() {
		for {
			_, msgBytes, readErr := wsConn.ReadMessage()
			if readErr != nil {
				return
			}
			lines := strings.Split(string(msgBytes), "\n")
			for _, line := range lines {
				line = strings.TrimSpace(line)
				if line == "" {
					continue
				}
				var ev ws.Event
				if parseErr := json.Unmarshal([]byte(line), &ev); parseErr == nil {
					receivedEvents <- ev
				}
			}
		}
	}()

	// 6. Test Scenario 8.1: Autonomous Multi-Agent Development Pipeline
	sessionID := "sess_e2e_1"
	leadNodeID := "node_lead_01"

	// Step A: Lead Node Created
	leadNodeEvent := ws.Event{
		Event:     "graph.node_created",
		SessionID: sessionID,
		NodeID:    leadNodeID,
		Data: map[string]any{
			"agent_name":   "Lead Architect",
			"role":         "lead",
			"depth":        0,
			"input_prompt": "Create discount calculator class and unit tests",
		},
	}
	wsHub.Broadcast(leadNodeEvent)
	ndjson.Log(sessionID, leadNodeID, leadNodeEvent.Event, leadNodeEvent.Data)

	// Step B: LLM Chat Stream through proxy
	chatReq := llm.ChatRequest{
		Model: "gpt-4o",
		Messages: []llm.ChatMessage{
			{Role: "user", Content: "Create discount calculator"},
		},
		SessionID: sessionID,
		NodeID:    leadNodeID,
	}

	chatResp, err := llmClient.ChatStream(context.Background(), chatReq, func(delta string, reasoning string, tc []llm.ToolCall) error {
		if delta != "" {
			wsHub.Broadcast(ws.Event{
				Event:     "graph.node_stream",
				SessionID: sessionID,
				NodeID:    leadNodeID,
				Data:      map[string]any{"delta": delta},
			})
		}
		return nil
	})
	if err != nil {
		t.Fatalf("chat stream failed: %v", err)
	}

	if len(chatResp.ToolCalls) != 1 || chatResp.ToolCalls[0].Function.Name != "call_sub_agent" {
		t.Fatalf("expected call_sub_agent tool call, got: %+v", chatResp.ToolCalls)
	}

	// Step C: Spawn Coder Sub-Agent Node
	coderNodeID := "node_coder_02"
	coderNodeEvent := ws.Event{
		Event:     "graph.node_created",
		SessionID: sessionID,
		NodeID:    coderNodeID,
		Data: map[string]any{
			"parent_node_id": leadNodeID,
			"agent_name":     "Software Engineer",
			"role":           "coder",
			"depth":          1,
		},
	}
	wsHub.Broadcast(coderNodeEvent)
	ndjson.Log(sessionID, coderNodeID, coderNodeEvent.Event, coderNodeEvent.Data)

	// Step D: Coder executes write_file in workspace
	calcFile := filepath.Join(workspaceDir, "DiscountCalculator.php")
	calcCode := `<?php
class DiscountCalculator {
    public function calculate(float $total, float $discountPercent): float {
        return $total - ($total * ($discountPercent / 100));
    }
}`
	if err := os.WriteFile(calcFile, []byte(calcCode), 0644); err != nil {
		t.Fatalf("failed to write calculator file: %v", err)
	}

	// Step E: Tester Sub-Agent Runs Local / Docker command
	testerNodeID := "node_tester_03"
	wsHub.Broadcast(ws.Event{
		Event:     "graph.node_created",
		SessionID: sessionID,
		NodeID:    testerNodeID,
		Data: map[string]any{
			"parent_node_id": leadNodeID,
			"agent_name":     "QA / Test Engineer",
			"role":           "tester",
			"depth":          1,
		},
	})
	ndjson.Log(sessionID, testerNodeID, "graph.node_created", map[string]any{"role": "tester"})

	execRes, err := localRunner.ExecLocal(context.Background(), []string{"powershell -Command Write-Output 'Tests Passed: 5 assertions'"}, workspaceDir, 5*time.Second)
	if err != nil {
		t.Fatalf("local runner error: %v", err)
	}
	if !strings.Contains(execRes.Stdout, "Tests Passed") {
		t.Fatalf("unexpected runner output: %s", execRes.Stdout)
	}

	// Step F: Complete Nodes
	wsHub.Broadcast(ws.Event{
		Event:     "graph.node_completed",
		SessionID: sessionID,
		NodeID:    coderNodeID,
		Data:      map[string]any{"output_result": "DiscountCalculator implemented", "status": "completed"},
	})
	wsHub.Broadcast(ws.Event{
		Event:     "graph.node_completed",
		SessionID: sessionID,
		NodeID:    leadNodeID,
		Data:      map[string]any{"output_result": "Feature and tests complete", "status": "completed"},
	})

	// 7. Verify WebSocket events received by client
	time.Sleep(100 * time.Millisecond)
	eventCount := len(receivedEvents)
	if eventCount < 4 {
		t.Fatalf("expected at least 4 events over WS, got %d", eventCount)
	}

	// 8. Verify NDJSON trace log files were created on disk
	leadLog := filepath.Join(logDir, sessionID, leadNodeID+".ndjson")
	if _, err := os.Stat(leadLog); os.IsNotExist(err) {
		t.Fatalf("expected ndjson file %s to exist", leadLog)
	}

	coderLog := filepath.Join(logDir, sessionID, coderNodeID+".ndjson")
	if _, err := os.Stat(coderLog); os.IsNotExist(err) {
		t.Fatalf("expected ndjson file %s to exist", coderLog)
	}
}

// TestScenarioHumanInTheLoop tests the AskHumanExpert flow with WS pause & resume
func TestScenarioHumanInTheLoop(t *testing.T) {
	wsHub := ws.NewHub()
	go wsHub.Run()

	server := httptest.NewServer(http.HandlerFunc(wsHub.HandleWebSocket))
	defer server.Close()

	wsURL := "ws" + strings.TrimPrefix(server.URL, "http") + "/ws?session_id=sess_human_test"
	dialer := websocket.Dialer{}
	conn, _, err := dialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("failed to connect ws: %v", err)
	}
	defer conn.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	// Simulate human answering after UI receives prompt
	go func() {
		time.Sleep(150 * time.Millisecond)
		ok := wsHub.AnswerHuman("node_lead_human", "Confirmed: Apply 15% VIP discount limit")
		if !ok {
			t.Errorf("AnswerHuman failed to fulfill waiter")
		}
	}()

	answer, err := wsHub.AskHuman(ctx, "sess_human_test", "node_lead_human", "Should VIP discount be capped at 15%?", []string{"yes", "no"})
	if err != nil {
		t.Fatalf("AskHuman returned error: %v", err)
	}

	if answer != "Confirmed: Apply 15% VIP discount limit" {
		t.Fatalf("unexpected answer: %s", answer)
	}
}
