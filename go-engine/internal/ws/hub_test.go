package ws

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

func TestWebSocketHubAndEvents(t *testing.T) {
	hub := NewHub()
	go hub.Run()

	server := httptest.NewServer(http.HandlerFunc(hub.HandleWebSocket))
	defer server.Close()

	wsURL := "ws" + strings.TrimPrefix(server.URL, "http") + "/ws?session_id=sess_123"

	dialer := websocket.Dialer{}
	conn, _, err := dialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("failed to dial websocket: %v", err)
	}
	defer conn.Close()

	// Wait briefly for connection registration
	time.Sleep(50 * time.Millisecond)

	// Broadcast node_created event
	hub.Broadcast(Event{
		Event:     "graph.node_created",
		SessionID: "sess_123",
		NodeID:    "node_lead",
		Data: map[string]any{
			"agent_name": "Lead Architect",
			"role":       "lead",
		},
	})

	_ = conn.SetReadDeadline(time.Now().Add(2 * time.Second))
	_, msgBytes, err := conn.ReadMessage()
	if err != nil {
		t.Fatalf("failed to read ws message: %v", err)
	}

	if !strings.Contains(string(msgBytes), "graph.node_created") {
		t.Fatalf("expected graph.node_created, got: %s", string(msgBytes))
	}
}

func TestHubHumanAskAndAnswer(t *testing.T) {
	hub := NewHub()
	go hub.Run()

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	go func() {
		time.Sleep(100 * time.Millisecond)
		ok := hub.AnswerHuman("node_question_1", "User Approved Architecture")
		if !ok {
			t.Errorf("expected AnswerHuman to succeed")
		}
	}()

	answer, err := hub.AskHuman(ctx, "sess_abc", "node_question_1", "Approve design?", []string{"yes", "no"})
	if err != nil {
		t.Fatalf("AskHuman failed: %v", err)
	}

	if answer != "User Approved Architecture" {
		t.Fatalf("expected 'User Approved Architecture', got '%s'", answer)
	}
}
