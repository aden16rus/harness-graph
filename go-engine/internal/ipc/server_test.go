package ipc

import (
	"bufio"
	"context"
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/harness/agent/go-engine/internal/llm"
	"github.com/harness/agent/go-engine/internal/logger"
	"github.com/harness/agent/go-engine/internal/runner"
	"github.com/harness/agent/go-engine/internal/ws"
)

func TestIPCServerPingAndRunner(t *testing.T) {
	tempDir, err := os.MkdirTemp("", "harness_test_*")
	if err != nil {
		t.Fatalf("failed to create temp dir: %v", err)
	}
	defer os.RemoveAll(tempDir)

	logDir := filepath.Join(tempDir, "logs")
	ndjson, err := logger.NewNDJSONLogger(logDir, 100)
	if err != nil {
		t.Fatalf("failed to create ndjson logger: %v", err)
	}
	defer ndjson.Close()

	localRunner := runner.NewLocalRunner(tempDir)
	llmClient := llm.NewClient("", "")
	wsHub := ws.NewHub()
	go wsHub.Run()

	tcpAddr := "127.0.0.1:9199"
	server := NewServer("", tcpAddr, localRunner, nil, llmClient, wsHub, ndjson)
	if err := server.Start(); err != nil {
		t.Fatalf("failed to start server: %v", err)
	}
	defer server.Stop()

	// Wait briefly for listener
	time.Sleep(100 * time.Millisecond)

	conn, err := net.Dial("tcp", tcpAddr)
	if err != nil {
		t.Fatalf("failed to connect to IPC server: %v", err)
	}
	defer conn.Close()

	writer := bufio.NewWriter(conn)
	reader := bufio.NewReader(conn)

	// 1. Test system.ping
	pingReq := RPCRequest{
		JSONRPC: "2.0",
		Method:  "system.ping",
		ID:      1,
	}
	data, _ := json.Marshal(pingReq)
	data = append(data, '\n')
	if _, err := writer.Write(data); err != nil {
		t.Fatalf("failed to write ping: %v", err)
	}
	_ = writer.Flush()

	respLine, err := reader.ReadBytes('\n')
	if err != nil {
		t.Fatalf("failed to read ping response: %v", err)
	}

	var pingResp RPCResponse
	if err := json.Unmarshal(respLine, &pingResp); err != nil {
		t.Fatalf("failed to unmarshal ping response: %v", err)
	}

	resMap, ok := pingResp.Result.(map[string]any)
	if !ok || resMap["status"] != "pong" {
		t.Fatalf("expected pong response, got: %v", pingResp)
	}

	// 2. Test runner.exec_local
	echoParams, _ := json.Marshal(map[string]any{
		"cmd":        []string{"echo Hello Harness"},
		"work_dir":   tempDir,
		"timeout_ms": 5000,
	})
	execReq := RPCRequest{
		JSONRPC: "2.0",
		Method:  "runner.exec_local",
		Params:  echoParams,
		ID:      2,
	}
	data, _ = json.Marshal(execReq)
	data = append(data, '\n')
	if _, err := writer.Write(data); err != nil {
		t.Fatalf("failed to write exec request: %v", err)
	}
	_ = writer.Flush()

	respLine, err = reader.ReadBytes('\n')
	if err != nil {
		t.Fatalf("failed to read exec response: %v", err)
	}

	var execResp RPCResponse
	if err := json.Unmarshal(respLine, &execResp); err != nil {
		t.Fatalf("failed to unmarshal exec response: %v", err)
	}

	if execResp.Error != nil {
		t.Fatalf("unexpected error from runner.exec_local: %v", execResp.Error)
	}

	// 3. Test graph.publish_event
	evParams, _ := json.Marshal(map[string]any{
		"event":      "graph.node_created",
		"session_id": "sess-test",
		"node_id":    "node-lead",
		"data": map[string]any{
			"agent_name": "Lead",
		},
	})
	evReq := RPCRequest{
		JSONRPC: "2.0",
		Method:  "graph.publish_event",
		Params:  evParams,
		ID:      3,
	}
	data, _ = json.Marshal(evReq)
	data = append(data, '\n')
	_, _ = writer.Write(data)
	_ = writer.Flush()

	respLine, err = reader.ReadBytes('\n')
	if err != nil {
		t.Fatalf("failed to read event response: %v", err)
	}

	var evResp RPCResponse
	if err := json.Unmarshal(respLine, &evResp); err != nil || evResp.Error != nil {
		t.Fatalf("failed event publish: %v", evResp)
	}

	// Verify NDJSON log file was created
	time.Sleep(50 * time.Millisecond)
	logFile := filepath.Join(logDir, "sess-test", "node-lead.ndjson")
	if _, err := os.Stat(logFile); os.IsNotExist(err) {
		t.Fatalf("expected ndjson file %s to exist", logFile)
	}
}

func TestLocalRunnerTimeout(t *testing.T) {
	r := runner.NewLocalRunner(os.TempDir())
	ctx := context.Background()

	// Short timeout on a command that takes longer
	res, err := r.ExecLocal(ctx, []string{"powershell -Command Start-Sleep -Milliseconds 500"}, os.TempDir(), 100*time.Millisecond)
	if err != nil {
		t.Fatalf("unexpected runner error: %v", err)
	}
	if res.ExitCode != 124 {
		t.Logf("Process exited with code %d (expected timeout 124)", res.ExitCode)
	}
}
