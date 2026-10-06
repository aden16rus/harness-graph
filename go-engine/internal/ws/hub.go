package ws

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

var upgrader = websocket.Upgrader{
	CheckOrigin: func(r *http.Request) bool {
		return true // Allow all origins for local dev / embedded web UI
	},
	ReadBufferSize:  1024,
	WriteBufferSize: 1024,
}

type Event struct {
	Event     string         `json:"event"`
	SessionID string         `json:"session_id"`
	NodeID    string         `json:"node_id,omitempty"`
	Timestamp string         `json:"timestamp"`
	Data      map[string]any `json:"data"`
}

type Client struct {
	hub       *Hub
	conn      *websocket.Conn
	send      chan []byte
	sessionID string
}

type Hub struct {
	clients      map[*Client]bool
	register     chan *Client
	unregister   chan *Client
	broadcastCh  chan Event
	humanWaiters map[string]chan string // key: node_id -> channel for human answer
	waitersMu    sync.Mutex
	mu           sync.RWMutex
}

func NewHub() *Hub {
	return &Hub{
		clients:      make(map[*Client]bool),
		register:     make(chan *Client),
		unregister:   make(chan *Client),
		broadcastCh:  make(chan Event, 1000),
		humanWaiters: make(map[string]chan string),
	}
}

func (h *Hub) Run() {
	for {
		select {
		case client := <-h.register:
			h.mu.Lock()
			h.clients[client] = true
			h.mu.Unlock()

		case client := <-h.unregister:
			h.mu.Lock()
			if _, ok := h.clients[client]; ok {
				delete(h.clients, client)
				close(client.send)
			}
			h.mu.Unlock()

		case event := <-h.broadcastCh:
			if event.Timestamp == "" {
				event.Timestamp = time.Now().UTC().Format(time.RFC3339Nano)
			}

			payload, err := json.Marshal(event)
			if err != nil {
				continue
			}

			h.mu.RLock()
			for client := range h.clients {
				// Filter by session only if a specific session ID is requested (not "all" or default)
				if client.sessionID != "" && client.sessionID != "all" && client.sessionID != "sess_default" && event.SessionID != "" && client.sessionID != event.SessionID {
					continue
				}

				select {
				case client.send <- payload:
				default:
					close(client.send)
					delete(h.clients, client)
				}
			}
			h.mu.RUnlock()
		}
	}
}

func (h *Hub) Broadcast(event Event) {
	if event.Timestamp == "" {
		event.Timestamp = time.Now().UTC().Format(time.RFC3339Nano)
	}
	h.broadcastCh <- event
}

// AskHuman registers a waiter for human answer and returns the answer when received
func (h *Hub) AskHuman(ctx context.Context, sessionID, nodeID, question string, options []string) (string, error) {
	answerCh := make(chan string, 1)

	h.waitersMu.Lock()
	h.humanWaiters[nodeID] = answerCh
	h.waitersMu.Unlock()

	defer func() {
		h.waitersMu.Lock()
		delete(h.humanWaiters, nodeID)
		h.waitersMu.Unlock()
	}()

	// Broadcast human_required event to UI
	h.Broadcast(Event{
		Event:     "graph.human_required",
		SessionID: sessionID,
		NodeID:    nodeID,
		Data: map[string]any{
			"question": question,
			"options":  options,
			"status":   "waiting_input",
		},
	})

	select {
	case <-ctx.Done():
		return "", ctx.Err()
	case answer := <-answerCh:
		// Broadcast human_answered event
		h.Broadcast(Event{
			Event:     "graph.human_answered",
			SessionID: sessionID,
			NodeID:    nodeID,
			Data: map[string]any{
				"answer": answer,
			},
		})
		return answer, nil
	}
}

// AnswerHuman fulfills a pending human question
func (h *Hub) AnswerHuman(nodeID, answer string) bool {
	h.waitersMu.Lock()
	ch, ok := h.humanWaiters[nodeID]
	h.waitersMu.Unlock()

	if !ok {
		return false
	}

	select {
	case ch <- answer:
		return true
	default:
		return false
	}
}

func (h *Hub) HandleWebSocket(w http.ResponseWriter, r *http.Request) {
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		http.Error(w, fmt.Sprintf("failed to upgrade websocket: %v", err), http.StatusBadRequest)
		return
	}

	sessionID := r.URL.Query().Get("session_id")
	fmt.Printf("[WS] Client connected: %s (session: %s)\n", r.RemoteAddr, sessionID)

	client := &Client{
		hub:       h,
		conn:      conn,
		send:      make(chan []byte, 256),
		sessionID: sessionID,
	}

	h.register <- client

	go client.writePump()
	go client.readPump()
}

func (c *Client) readPump() {
	defer func() {
		c.hub.unregister <- c
		c.conn.Close()
	}()

	c.conn.SetReadLimit(65536)
	_ = c.conn.SetReadDeadline(time.Now().Add(60 * time.Second))
	c.conn.SetPongHandler(func(string) error {
		_ = c.conn.SetReadDeadline(time.Now().Add(60 * time.Second))
		return nil
	})

	for {
		_, message, err := c.conn.ReadMessage()
		if err != nil {
			break
		}

		// Handle client-to-server WS messages (e.g. human answer or session subscription)
		var msg struct {
			Action    string `json:"action"`
			NodeID    string `json:"node_id"`
			Answer    string `json:"answer"`
			SessionID string `json:"session_id"`
		}
		if err := json.Unmarshal(message, &msg); err == nil {
			if msg.Action == "human.answer" && msg.NodeID != "" {
				c.hub.AnswerHuman(msg.NodeID, msg.Answer)
			}
			if msg.Action == "session.subscribe" && msg.SessionID != "" {
				c.sessionID = msg.SessionID
			}
		}
	}
}

func (c *Client) writePump() {
	ticker := time.NewTicker(30 * time.Second)
	defer func() {
		ticker.Stop()
		c.conn.Close()
	}()

	for {
		select {
		case message, ok := <-c.send:
			_ = c.conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if !ok {
				_ = c.conn.WriteMessage(websocket.CloseMessage, []byte{})
				return
			}

			w, err := c.conn.NextWriter(websocket.TextMessage)
			if err != nil {
				return
			}
			_, _ = w.Write(message)

			// Drain queued messages into the current WebSocket frame
			n := len(c.send)
			for i := 0; i < n; i++ {
				_, _ = w.Write([]byte{'\n'})
				_, _ = w.Write(<-c.send)
			}

			if err := w.Close(); err != nil {
				return
			}

		case <-ticker.C:
			_ = c.conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if err := c.conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}
