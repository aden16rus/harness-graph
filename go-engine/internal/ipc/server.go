package ipc

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"os"
	"runtime"
	"sync"
	"time"

	"github.com/harness/agent/go-engine/internal/docker"
	"github.com/harness/agent/go-engine/internal/llm"
	"github.com/harness/agent/go-engine/internal/logger"
	"github.com/harness/agent/go-engine/internal/runner"
	"github.com/harness/agent/go-engine/internal/ws"
)

type RPCRequest struct {
	JSONRPC string          `json:"jsonrpc"`
	Method  string          `json:"method"`
	Params  json.RawMessage `json:"params"`
	ID      any             `json:"id"`
}

type RPCResponse struct {
	JSONRPC string    `json:"jsonrpc"`
	Result  any       `json:"result,omitempty"`
	Error   *RPCError `json:"error,omitempty"`
	ID      any       `json:"id"`
}

type RPCError struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
	Data    any    `json:"data,omitempty"`
}

type Server struct {
	unixPath        string
	tcpAddr         string
	runner          *runner.LocalRunner
	docker          *docker.DockerEngine
	llmClient       *llm.Client
	wsHub           *ws.Hub
	ndjson          *logger.NDJSONLogger
	profileResolver func(profileID string) *llm.Client
	listeners       []net.Listener
	mu              sync.Mutex
	quitCh          chan struct{}
}

func (s *Server) SetProfileResolver(fn func(profileID string) *llm.Client) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.profileResolver = fn
}

func NewServer(
	unixPath, tcpAddr string,
	r *runner.LocalRunner,
	d *docker.DockerEngine,
	l *llm.Client,
	h *ws.Hub,
	nd *logger.NDJSONLogger,
) *Server {
	return &Server{
		unixPath:  unixPath,
		tcpAddr:   tcpAddr,
		runner:    r,
		docker:    d,
		llmClient: l,
		wsHub:     h,
		ndjson:    nd,
		quitCh:    make(chan struct{}),
	}
}

func (s *Server) Start() error {
	var listeners []net.Listener

	// Listen on Unix domain socket if configured and not Windows (or if Windows supports AF_UNIX)
	if s.unixPath != "" && runtime.GOOS != "windows" {
		_ = os.Remove(s.unixPath)
		uListener, err := net.Listen("unix", s.unixPath)
		if err != nil {
			return fmt.Errorf("failed to listen on unix socket %s: %w", s.unixPath, err)
		}
		_ = os.Chmod(s.unixPath, 0777)
		listeners = append(listeners, uListener)
		fmt.Printf("[IPC] Listening on Unix domain socket: %s\n", s.unixPath)
	}

	// Also listen on TCP address (always available, crucial for Windows and cross-container dev)
	if s.tcpAddr != "" {
		tListener, err := net.Listen("tcp", s.tcpAddr)
		if err != nil {
			return fmt.Errorf("failed to listen on tcp address %s: %w", s.tcpAddr, err)
		}
		listeners = append(listeners, tListener)
		fmt.Printf("[IPC] Listening on TCP: %s\n", s.tcpAddr)
	}

	if len(listeners) == 0 {
		return fmt.Errorf("no valid listeners configured for IPC server")
	}

	s.listeners = listeners
	for _, l := range listeners {
		go s.acceptLoop(l)
	}

	return nil
}

func (s *Server) Stop() {
	close(s.quitCh)
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, l := range s.listeners {
		_ = l.Close()
	}
	if s.unixPath != "" && runtime.GOOS != "windows" {
		_ = os.Remove(s.unixPath)
	}
}

func (s *Server) acceptLoop(l net.Listener) {
	for {
		conn, err := l.Accept()
		if err != nil {
			select {
			case <-s.quitCh:
				return
			default:
				continue
			}
		}

		go s.handleConnection(conn)
	}
}

func (s *Server) handleConnection(conn net.Conn) {
	defer conn.Close()

	reader := bufio.NewReader(conn)
	writer := bufio.NewWriter(conn)

	for {
		line, err := reader.ReadBytes('\n')
		if err != nil {
			if err != io.EOF {
				fmt.Fprintf(os.Stderr, "[IPC] Connection read error: %v\n", err)
			}
			return
		}

		var req RPCRequest
		if err := json.Unmarshal(line, &req); err != nil {
			resp := RPCResponse{
				JSONRPC: "2.0",
				Error:   &RPCError{Code: -32700, Message: "Parse error: " + err.Error()},
				ID:      nil,
			}
			s.sendResponse(writer, resp)
			continue
		}

		resp := s.dispatch(context.Background(), req)
		s.sendResponse(writer, resp)
	}
}

func (s *Server) sendResponse(w *bufio.Writer, resp RPCResponse) {
	data, err := json.Marshal(resp)
	if err != nil {
		return
	}
	data = append(data, '\n')
	_, _ = w.Write(data)
	_ = w.Flush()
}

func (s *Server) dispatch(ctx context.Context, req RPCRequest) RPCResponse {
	resp := RPCResponse{
		JSONRPC: "2.0",
		ID:      req.ID,
	}

	switch req.Method {
	case "system.ping":
		resp.Result = map[string]any{
			"status": "pong",
			"time":   time.Now().UTC().Format(time.RFC3339),
			"os":     runtime.GOOS,
		}

	case "runner.exec_local":
		var p struct {
			Cmd       []string `json:"cmd"`
			WorkDir   string   `json:"work_dir"`
			TimeoutMs int64    `json:"timeout_ms"`
		}
		if err := json.Unmarshal(req.Params, &p); err != nil {
			resp.Error = &RPCError{Code: -32602, Message: "Invalid params: " + err.Error()}
			return resp
		}

		timeout := time.Duration(p.TimeoutMs) * time.Millisecond
		res, err := s.runner.ExecLocal(ctx, p.Cmd, p.WorkDir, timeout)
		if err != nil {
			resp.Error = &RPCError{Code: -32000, Message: err.Error()}
			return resp
		}
		resp.Result = res

	case "docker.exec":
		var p struct {
			ContainerID string   `json:"container_id"`
			Cmd         []string `json:"cmd"`
			WorkDir     string   `json:"work_dir"`
			TimeoutMs   int64    `json:"timeout_ms"`
		}
		if err := json.Unmarshal(req.Params, &p); err != nil {
			resp.Error = &RPCError{Code: -32602, Message: "Invalid params: " + err.Error()}
			return resp
		}

		if s.docker == nil {
			resp.Error = &RPCError{Code: -32001, Message: "Docker engine not initialized on daemon"}
			return resp
		}

		timeout := time.Duration(p.TimeoutMs) * time.Millisecond
		res, err := s.docker.ExecInContainer(ctx, p.ContainerID, p.Cmd, p.WorkDir, timeout)
		if err != nil {
			resp.Error = &RPCError{Code: -32000, Message: err.Error()}
			return resp
		}
		resp.Result = res

	case "llm.chat":
		var chatReq llm.ChatRequest
		if err := json.Unmarshal(req.Params, &chatReq); err != nil {
			resp.Error = &RPCError{Code: -32602, Message: "Invalid params: " + err.Error()}
			return resp
		}

		clientToUse := s.llmClient
		s.mu.Lock()
		resolver := s.profileResolver
		s.mu.Unlock()

		if resolver != nil && chatReq.LLMProfileID != "" {
			if customClient := resolver(chatReq.LLMProfileID); customClient != nil {
				clientToUse = customClient
			}
		}

		// Stream to WS Hub while processing
		res, err := clientToUse.ChatStream(ctx, chatReq, func(delta string, reasoningDelta string, toolCalls []llm.ToolCall) error {
			if s.wsHub != nil {
				if delta != "" {
					s.wsHub.Broadcast(ws.Event{
						Event:     "graph.node_stream",
						SessionID: chatReq.SessionID,
						NodeID:    chatReq.NodeID,
						Data: map[string]any{
							"delta": delta,
						},
					})
				}
				if reasoningDelta != "" {
					s.wsHub.Broadcast(ws.Event{
						Event:     "graph.node_stream",
						SessionID: chatReq.SessionID,
						NodeID:    chatReq.NodeID,
						Data: map[string]any{
							"reasoning_delta": reasoningDelta,
						},
					})
				}
			}
			return nil
		})

		if err != nil {
			resp.Error = &RPCError{Code: -32000, Message: err.Error()}
			return resp
		}
		resp.Result = res

	case "graph.publish_event":
		var ev ws.Event
		if err := json.Unmarshal(req.Params, &ev); err != nil {
			resp.Error = &RPCError{Code: -32602, Message: "Invalid params: " + err.Error()}
			return resp
		}

		if s.wsHub != nil {
			s.wsHub.Broadcast(ev)
		}
		if s.ndjson != nil {
			s.ndjson.Log(ev.SessionID, ev.NodeID, ev.Event, ev.Data)
		}
		resp.Result = map[string]any{"published": true}

	case "human.ask":
		var p struct {
			SessionID string   `json:"session_id"`
			NodeID    string   `json:"node_id"`
			Question  string   `json:"question"`
			Options   []string `json:"options"`
			TimeoutMs int64    `json:"timeout_ms"`
		}
		if err := json.Unmarshal(req.Params, &p); err != nil {
			resp.Error = &RPCError{Code: -32602, Message: "Invalid params: " + err.Error()}
			return resp
		}

		timeout := 10 * time.Minute
		if p.TimeoutMs > 0 {
			timeout = time.Duration(p.TimeoutMs) * time.Millisecond
		}
		askCtx, cancel := context.WithTimeout(ctx, timeout)
		defer cancel()

		answer, err := s.wsHub.AskHuman(askCtx, p.SessionID, p.NodeID, p.Question, p.Options)
		if err != nil {
			resp.Error = &RPCError{Code: -32002, Message: "Human response error: " + err.Error()}
			return resp
		}
		resp.Result = map[string]any{"answer": answer}

	case "log.event":
		var p struct {
			SessionID string         `json:"session_id"`
			NodeID    string         `json:"node_id"`
			EventType string         `json:"event_type"`
			Payload   map[string]any `json:"payload"`
		}
		if err := json.Unmarshal(req.Params, &p); err != nil {
			resp.Error = &RPCError{Code: -32602, Message: "Invalid params: " + err.Error()}
			return resp
		}

		if s.ndjson != nil {
			s.ndjson.Log(p.SessionID, p.NodeID, p.EventType, p.Payload)
		}
		resp.Result = map[string]any{"logged": true}

	default:
		resp.Error = &RPCError{Code: -32601, Message: fmt.Sprintf("Method not found: %s", req.Method)}
	}

	return resp
}
