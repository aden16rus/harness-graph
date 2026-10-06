package logger

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"time"
)

type LogEntry struct {
	SessionID string         `json:"session_id"`
	NodeID    string         `json:"node_id"`
	EventType string         `json:"event_type"`
	Timestamp string         `json:"timestamp"`
	Payload   map[string]any `json:"payload"`
}

type NDJSONLogger struct {
	baseDir  string
	entryCh  chan LogEntry
	wg       sync.WaitGroup
	quitCh   chan struct{}
	mu       sync.Mutex
	openDirs map[string]struct{}
}

func NewNDJSONLogger(baseDir string, bufferSize int) (*NDJSONLogger, error) {
	if err := os.MkdirAll(baseDir, 0755); err != nil {
		return nil, fmt.Errorf("failed to create log directory: %w", err)
	}

	if bufferSize <= 0 {
		bufferSize = 1000
	}

	l := &NDJSONLogger{
		baseDir:  baseDir,
		entryCh:  make(chan LogEntry, bufferSize),
		quitCh:   make(chan struct{}),
		openDirs: make(map[string]struct{}),
	}

	l.wg.Add(1)
	go l.worker()

	return l, nil
}

func (l *NDJSONLogger) Log(sessionID, nodeID, eventType string, payload map[string]any) {
	if sessionID == "" {
		sessionID = "global"
	}
	if nodeID == "" {
		nodeID = "system"
	}

	entry := LogEntry{
		SessionID: sessionID,
		NodeID:    nodeID,
		EventType: eventType,
		Timestamp: time.Now().UTC().Format(time.RFC3339Nano),
		Payload:   payload,
	}

	select {
	case l.entryCh <- entry:
	default:
		// Drop or fallback write to avoid blocking if buffer is saturated
		fmt.Fprintf(os.Stderr, "[NDJSONLogger] Warning: log buffer full, dropping event %s for %s/%s\n", eventType, sessionID, nodeID)
	}
}

func (l *NDJSONLogger) worker() {
	defer l.wg.Done()

	for {
		select {
		case entry := <-l.entryCh:
			l.writeEntry(entry)
		case <-l.quitCh:
			// Drain remaining entries
			for {
				select {
				case entry := <-l.entryCh:
					l.writeEntry(entry)
				default:
					return
				}
			}
		}
	}
}

func (l *NDJSONLogger) writeEntry(entry LogEntry) {
	sessionDir := filepath.Join(l.baseDir, entry.SessionID)

	l.mu.Lock()
	if _, ok := l.openDirs[sessionDir]; !ok {
		_ = os.MkdirAll(sessionDir, 0755)
		l.openDirs[sessionDir] = struct{}{}
	}
	l.mu.Unlock()

	filePath := filepath.Join(sessionDir, fmt.Sprintf("%s.ndjson", entry.NodeID))
	f, err := os.OpenFile(filePath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0644)
	if err != nil {
		fmt.Fprintf(os.Stderr, "[NDJSONLogger] Error opening file %s: %v\n", filePath, err)
		return
	}
	defer f.Close()

	data, err := json.Marshal(entry)
	if err != nil {
		fmt.Fprintf(os.Stderr, "[NDJSONLogger] Error marshaling entry: %v\n", err)
		return
	}

	data = append(data, '\n')
	_, _ = f.Write(data)
}

func (l *NDJSONLogger) Close() error {
	close(l.quitCh)
	l.wg.Wait()
	return nil
}
