package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/harness/agent/go-engine/internal/config"
	"github.com/harness/agent/go-engine/internal/docker"
	"github.com/harness/agent/go-engine/internal/ipc"
	"github.com/harness/agent/go-engine/internal/llm"
	"github.com/harness/agent/go-engine/internal/logger"
	"github.com/harness/agent/go-engine/internal/runner"
	"github.com/harness/agent/go-engine/internal/ws"
)

type LLMProfile struct {
	ID           string `json:"id"`
	Name         string `json:"name"`
	BaseURL      string `json:"base_url"`
	APIKey       string `json:"api_key"`
	DefaultModel string `json:"default_model"`
	IsActive     bool   `json:"is_active"`
}

type LLMProfilesStore struct {
	ActiveProfileID string       `json:"active_profile_id"`
	Profiles        []LLMProfile `json:"profiles"`
	mu              sync.RWMutex
	filePath        string
}

func newLLMProfilesStore(dataDir string, cfg *config.Config) *LLMProfilesStore {
	filePath := filepath.Join(dataDir, "llm_profiles.json")
	store := &LLMProfilesStore{
		filePath: filePath,
	}

	if data, err := os.ReadFile(filePath); err == nil {
		if err := json.Unmarshal(data, store); err == nil && len(store.Profiles) > 0 {
			// Find active profile
			for _, p := range store.Profiles {
				if p.ID == store.ActiveProfileID {
					cfg.OpenAIBaseURL = p.BaseURL
					if p.APIKey != "" {
						cfg.OpenAIKey = p.APIKey
					}
					cfg.DefaultModel = p.DefaultModel
					break
				}
			}
			return store
		}
	}

	// Default pre-populated profiles
	store.ActiveProfileID = "prof_openai"
	store.Profiles = []LLMProfile{
		{
			ID:           "prof_openai",
			Name:         "OpenAI Official",
			BaseURL:      "https://api.openai.com/v1",
			APIKey:       cfg.OpenAIKey,
			DefaultModel: "gpt-4o",
			IsActive:     true,
		},
		{
			ID:           "prof_deepseek",
			Name:         "DeepSeek API",
			BaseURL:      "https://api.deepseek.com/v1",
			APIKey:       "",
			DefaultModel: "deepseek-chat",
			IsActive:     false,
		},
		{
			ID:           "prof_openrouter",
			Name:         "OpenRouter (Multi-model)",
			BaseURL:      "https://openrouter.ai/api/v1",
			APIKey:       "",
			DefaultModel: "anthropic/claude-3.5-sonnet",
			IsActive:     false,
		},
		{
			ID:           "prof_ollama",
			Name:         "Local Ollama",
			BaseURL:      "http://127.0.0.1:11434/v1",
			APIKey:       "",
			DefaultModel: "llama3.1",
			IsActive:     false,
		},
	}
	_ = store.save()
	return store
}

func (s *LLMProfilesStore) save() error {
	s.mu.Lock()
	defer s.mu.Unlock()
	data, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(s.filePath, data, 0644)
}

func (s *LLMProfilesStore) getActive() *LLMProfile {
	s.mu.RLock()
	defer s.mu.RUnlock()
	for _, p := range s.Profiles {
		if p.ID == s.ActiveProfileID {
			cpy := p
			return &cpy
		}
	}
	if len(s.Profiles) > 0 {
		cpy := s.Profiles[0]
		return &cpy
	}
	return nil
}

type SystemSettings struct {
	SubAgentMaxSteps       int    `json:"subagent_max_steps"`
	RootMaxSteps           int    `json:"root_max_steps"`
	SubAgentMaxTokens      int    `json:"subagent_max_tokens"`
	LoopProtectionEnabled  bool   `json:"loop_protection_enabled"`
	LoopDetectionThreshold int    `json:"loop_detection_threshold"`
	LLMMaxRetries          int    `json:"llm_max_retries"`
	LLMRetryDelaySec       int    `json:"llm_retry_delay_sec"`
	GlobalSystemPrompt     string `json:"global_system_prompt"`
}

type SystemSettingsStore struct {
	Settings SystemSettings `json:"settings"`
	mu       sync.RWMutex
	filePath string
}

func newSystemSettingsStore(dataDir string) *SystemSettingsStore {
	filePath := filepath.Join(dataDir, "settings.json")
	store := &SystemSettingsStore{
		filePath: filePath,
		Settings: SystemSettings{
			SubAgentMaxSteps:       15,
			RootMaxSteps:           25,
			SubAgentMaxTokens:      50000,
			LoopProtectionEnabled:  true,
			LoopDetectionThreshold: 3,
			LLMMaxRetries:          3,
			LLMRetryDelaySec:       3,
			GlobalSystemPrompt:     "",
		},
	}

	if data, err := os.ReadFile(filePath); err == nil {
		var loaded SystemSettings
		if err := json.Unmarshal(data, &loaded); err == nil {
			if loaded.SubAgentMaxSteps > 0 {
				store.Settings.SubAgentMaxSteps = loaded.SubAgentMaxSteps
			}
			if loaded.RootMaxSteps > 0 {
				store.Settings.RootMaxSteps = loaded.RootMaxSteps
			}
			if loaded.SubAgentMaxTokens >= 0 {
				store.Settings.SubAgentMaxTokens = loaded.SubAgentMaxTokens
			}
			store.Settings.LoopProtectionEnabled = loaded.LoopProtectionEnabled
			if loaded.LoopDetectionThreshold >= 2 {
				store.Settings.LoopDetectionThreshold = loaded.LoopDetectionThreshold
			}
			if loaded.LLMMaxRetries >= 0 {
				store.Settings.LLMMaxRetries = loaded.LLMMaxRetries
			}
			if loaded.LLMRetryDelaySec > 0 {
				store.Settings.LLMRetryDelaySec = loaded.LLMRetryDelaySec
			}
			store.Settings.GlobalSystemPrompt = loaded.GlobalSystemPrompt
			return store
		}
	}

	_ = store.save()
	return store
}

func (s *SystemSettingsStore) save() error {
	s.mu.Lock()
	defer s.mu.Unlock()
	data, err := json.MarshalIndent(s.Settings, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(s.filePath, data, 0644)
}

func (s *SystemSettingsStore) Update(st SystemSettings) error {
	s.mu.Lock()
	s.Settings = st
	s.mu.Unlock()
	return s.save()
}

func (s *SystemSettingsStore) Get() SystemSettings {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.Settings
}

func runPHPCommand(phpBin, phpHarness string, args ...string) ([]byte, error) {
	cmdArgs := append([]string{phpHarness}, args...)
	cmd := exec.Command(phpBin, cmdArgs...)
	return cmd.CombinedOutput()
}

func runPHPCommandWithStdin(phpBin, phpHarness string, stdinData []byte, args ...string) ([]byte, error) {
	cmdArgs := append([]string{phpHarness}, args...)
	cmd := exec.Command(phpBin, cmdArgs...)
	if len(stdinData) > 0 {
		cmd.Stdin = bytes.NewReader(stdinData)
	}
	return cmd.CombinedOutput()
}

func main() {
	cfg := config.Load()
	fmt.Println("====================================================")
	fmt.Println("       Harness Agent Engine (Go Daemon)             ")
	fmt.Println("====================================================")
	fmt.Printf("Workspace Dir : %s\n", cfg.WorkspaceDir)
	fmt.Printf("Data Dir      : %s\n", cfg.DataDir)
	fmt.Printf("HTTP Port     : %s\n", cfg.HTTPPort)
	fmt.Printf("IPC Socket    : %s\n", cfg.IPCSocketPath)
	fmt.Printf("IPC TCP       : %s\n", cfg.IPCTCPAddr)

	// Profiles Store
	profilesStore := newLLMProfilesStore(cfg.DataDir, cfg)

	// Settings Store
	settingsStore := newSystemSettingsStore(cfg.DataDir)

	// 1. NDJSON Logger
	ndjson, err := logger.NewNDJSONLogger(cfg.LogDir, 2000)
	if err != nil {
		log.Fatalf("Failed to initialize NDJSON logger: %v", err)
	}
	defer ndjson.Close()

	// 2. Local Process Runner
	localRunner := runner.NewLocalRunner(cfg.WorkspaceDir)

	// 3. Docker Engine
	dockerEngine, err := docker.NewDockerEngine()
	if err != nil {
		log.Printf("[Warning] Docker engine initialization failed (%v). Docker skills will be unavailable until Docker is running.\n", err)
	} else {
		log.Println("[Docker] Docker client initialized successfully.")
	}

	// 4. LLM Gateway Client
	llmClient := llm.NewClient(cfg.OpenAIBaseURL, cfg.OpenAIKey)
	curSettings := settingsStore.Get()
	llmClient.SetRetryConfig(curSettings.LLMMaxRetries, curSettings.LLMRetryDelaySec)

	// 5. WebSocket Hub
	wsHub := ws.NewHub()
	go wsHub.Run()

	// 6. JSON-RPC IPC Server for PHP
	ipcServer := ipc.NewServer(cfg.IPCSocketPath, cfg.IPCTCPAddr, localRunner, dockerEngine, llmClient, wsHub, ndjson)
	ipcServer.SetProfileResolver(func(profileID string) *llm.Client {
		profilesStore.mu.RLock()
		defer profilesStore.mu.RUnlock()
		for _, p := range profilesStore.Profiles {
			if p.ID == profileID {
				client := llm.NewClient(p.BaseURL, p.APIKey)
				st := settingsStore.Get()
				client.SetRetryConfig(st.LLMMaxRetries, st.LLMRetryDelaySec)
				return client
			}
		}
		return nil
	})
	if err := ipcServer.Start(); err != nil {
		log.Fatalf("Failed to start IPC server: %v", err)
	}
	defer ipcServer.Stop()

	// 7. HTTP & WebSocket Router
	mux := http.NewServeMux()

	// WS endpoint
	mux.HandleFunc("/ws", wsHub.HandleWebSocket)

	// API Health
	mux.HandleFunc("/api/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"status":    "healthy",
			"timestamp": time.Now().UTC().Format(time.RFC3339),
			"version":   "1.0.0",
		})
	})

	// API Configuration (Active profile fast access)
	mux.HandleFunc("/api/config", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		if r.Method == http.MethodGet {
			_ = json.NewEncoder(w).Encode(map[string]any{
				"openai_base_url": cfg.OpenAIBaseURL,
				"default_model":   cfg.DefaultModel,
				"has_api_key":     cfg.OpenAIKey != "",
				"workspace_dir":   cfg.WorkspaceDir,
			})
			return
		}

		if r.Method == http.MethodPost {
			var body struct {
				OpenAIBaseURL string `json:"openai_base_url"`
				OpenAIKey     string `json:"openai_api_key"`
				DefaultModel  string `json:"default_model"`
			}
			if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
				http.Error(w, "Invalid body", http.StatusBadRequest)
				return
			}
			if body.OpenAIBaseURL != "" {
				cfg.OpenAIBaseURL = body.OpenAIBaseURL
			}
			if body.OpenAIKey != "" {
				cfg.OpenAIKey = body.OpenAIKey
			}
			if body.DefaultModel != "" {
				cfg.DefaultModel = body.DefaultModel
			}
			llmClient.UpdateConfig(cfg.OpenAIBaseURL, cfg.OpenAIKey)
			_ = json.NewEncoder(w).Encode(map[string]any{
				"ok":              true,
				"openai_base_url": cfg.OpenAIBaseURL,
				"default_model":   cfg.DefaultModel,
			})
			return
		}

		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
	})

	// API System Settings (Step limits, Anti-loop, LLM retry with pause)
	mux.HandleFunc("/api/settings", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		if r.Method == http.MethodGet {
			st := settingsStore.Get()
			_ = json.NewEncoder(w).Encode(st)
			return
		}

		if r.Method == http.MethodPost {
			var newSettings SystemSettings
			if err := json.NewDecoder(r.Body).Decode(&newSettings); err != nil {
				http.Error(w, "Invalid JSON: "+err.Error(), http.StatusBadRequest)
				return
			}
			if newSettings.SubAgentMaxSteps <= 0 {
				newSettings.SubAgentMaxSteps = 15
			}
			if newSettings.RootMaxSteps <= 0 {
				newSettings.RootMaxSteps = 25
			}
			if newSettings.LoopDetectionThreshold < 2 {
				newSettings.LoopDetectionThreshold = 3
			}
			if newSettings.LLMMaxRetries < 0 {
				newSettings.LLMMaxRetries = 0
			}
			if newSettings.LLMRetryDelaySec <= 0 {
				newSettings.LLMRetryDelaySec = 1
			}

			if err := settingsStore.Update(newSettings); err != nil {
				http.Error(w, "Failed to save settings: "+err.Error(), http.StatusInternalServerError)
				return
			}

			llmClient.SetRetryConfig(newSettings.LLMMaxRetries, newSettings.LLMRetryDelaySec)

			_ = json.NewEncoder(w).Encode(map[string]any{
				"ok":       true,
				"settings": newSettings,
			})
			return
		}

		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
	})

	// API LLM Profiles Management
	mux.HandleFunc("/api/llm-profiles", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")

		if r.Method == http.MethodGet {
			profilesStore.mu.RLock()
			defer profilesStore.mu.RUnlock()
			_ = json.NewEncoder(w).Encode(profilesStore)
			return
		}

		if r.Method == http.MethodPost {
			var payload struct {
				Action    string      `json:"action"` // "save", "activate", "delete"
				Profile   *LLMProfile `json:"profile"`
				ProfileID string      `json:"profile_id"`
			}
			if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
				http.Error(w, "Invalid JSON: "+err.Error(), http.StatusBadRequest)
				return
			}

			switch payload.Action {
			case "activate":
				if payload.ProfileID == "" {
					http.Error(w, "profile_id required", http.StatusBadRequest)
					return
				}
				profilesStore.mu.Lock()
				found := false
				for i := range profilesStore.Profiles {
					if profilesStore.Profiles[i].ID == payload.ProfileID {
						profilesStore.Profiles[i].IsActive = true
						profilesStore.ActiveProfileID = payload.ProfileID
						cfg.OpenAIBaseURL = profilesStore.Profiles[i].BaseURL
						cfg.OpenAIKey = profilesStore.Profiles[i].APIKey
						cfg.DefaultModel = profilesStore.Profiles[i].DefaultModel
						llmClient.UpdateConfig(cfg.OpenAIBaseURL, cfg.OpenAIKey)
						found = true
					} else {
						profilesStore.Profiles[i].IsActive = false
					}
				}
				profilesStore.mu.Unlock()

				if !found {
					http.Error(w, "Profile not found", http.StatusNotFound)
					return
				}
				_ = profilesStore.save()
				_ = json.NewEncoder(w).Encode(map[string]any{"ok": true, "active_profile_id": payload.ProfileID})

			case "save":
				if payload.Profile == nil {
					http.Error(w, "profile required", http.StatusBadRequest)
					return
				}
				if payload.Profile.ID == "" {
					payload.Profile.ID = fmt.Sprintf("prof_%d", time.Now().UnixNano())
				}

				profilesStore.mu.Lock()
				exists := false
				for i := range profilesStore.Profiles {
					if profilesStore.Profiles[i].ID == payload.Profile.ID {
						// Keep existing key if placeholder sent
						if payload.Profile.APIKey == "" && profilesStore.Profiles[i].APIKey != "" {
							payload.Profile.APIKey = profilesStore.Profiles[i].APIKey
						}
						profilesStore.Profiles[i] = *payload.Profile
						exists = true
						break
					}
				}
				if !exists {
					profilesStore.Profiles = append(profilesStore.Profiles, *payload.Profile)
				}

				// If active or first profile, sync to engine
				if payload.Profile.IsActive || len(profilesStore.Profiles) == 1 {
					profilesStore.ActiveProfileID = payload.Profile.ID
					for i := range profilesStore.Profiles {
						profilesStore.Profiles[i].IsActive = (profilesStore.Profiles[i].ID == payload.Profile.ID)
					}
					cfg.OpenAIBaseURL = payload.Profile.BaseURL
					cfg.OpenAIKey = payload.Profile.APIKey
					cfg.DefaultModel = payload.Profile.DefaultModel
					llmClient.UpdateConfig(cfg.OpenAIBaseURL, cfg.OpenAIKey)
				}
				profilesStore.mu.Unlock()

				_ = profilesStore.save()
				_ = json.NewEncoder(w).Encode(map[string]any{"ok": true, "profile": payload.Profile})

			case "delete":
				if payload.ProfileID == "" {
					http.Error(w, "profile_id required", http.StatusBadRequest)
					return
				}
				profilesStore.mu.Lock()
				var filtered []LLMProfile
				for _, p := range profilesStore.Profiles {
					if p.ID != payload.ProfileID {
						filtered = append(filtered, p)
					}
				}
				profilesStore.Profiles = filtered
				if profilesStore.ActiveProfileID == payload.ProfileID && len(filtered) > 0 {
					profilesStore.ActiveProfileID = filtered[0].ID
					filtered[0].IsActive = true
					cfg.OpenAIBaseURL = filtered[0].BaseURL
					cfg.OpenAIKey = filtered[0].APIKey
					cfg.DefaultModel = filtered[0].DefaultModel
					llmClient.UpdateConfig(cfg.OpenAIBaseURL, cfg.OpenAIKey)
				}
				profilesStore.mu.Unlock()

				_ = profilesStore.save()
				_ = json.NewEncoder(w).Encode(map[string]any{"ok": true})

			default:
				http.Error(w, "Unsupported action", http.StatusBadRequest)
			}
			return
		}

		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
	})

	// API Projects (Proxy to PHP Repository)
	mux.HandleFunc("/api/projects", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		if r.Method == http.MethodGet {
			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "projects:list")
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		if r.Method == http.MethodPost {
			body, err := io.ReadAll(r.Body)
			if err != nil {
				http.Error(w, "Invalid body: "+err.Error(), http.StatusBadRequest)
				return
			}
			out, err := runPHPCommandWithStdin(cfg.PHPBin, cfg.PHPHarness, body, "projects:save")
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		if r.Method == http.MethodDelete {
			id := r.URL.Query().Get("id")
			if id == "" {
				http.Error(w, "id query param required", http.StatusBadRequest)
				return
			}
			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "projects:delete", id)
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
	})

	// API Teams (Proxy to PHP Repository)
	mux.HandleFunc("/api/teams", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		if r.Method == http.MethodGet {
			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "teams:list")
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		if r.Method == http.MethodPost {
			body, err := io.ReadAll(r.Body)
			if err != nil {
				http.Error(w, "Invalid body: "+err.Error(), http.StatusBadRequest)
				return
			}
			out, err := runPHPCommandWithStdin(cfg.PHPBin, cfg.PHPHarness, body, "teams:save")
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		if r.Method == http.MethodDelete {
			id := r.URL.Query().Get("id")
			if id == "" {
				http.Error(w, "id query param required", http.StatusBadRequest)
				return
			}
			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "teams:delete", id)
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
	})

	// API Agents (Proxy to PHP Repository)
	mux.HandleFunc("/api/agents", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		if r.Method == http.MethodGet {
			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "agents:list")
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		if r.Method == http.MethodPost {
			body, err := io.ReadAll(r.Body)
			if err != nil {
				http.Error(w, "Invalid body: "+err.Error(), http.StatusBadRequest)
				return
			}
			out, err := runPHPCommandWithStdin(cfg.PHPBin, cfg.PHPHarness, body, "agents:save")
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		if r.Method == http.MethodDelete {
			id := r.URL.Query().Get("id")
			if id == "" {
				http.Error(w, "id query param required", http.StatusBadRequest)
				return
			}
			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "agents:delete", id)
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
	})

	// API Human Answer
	mux.HandleFunc("/api/human/answer", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}
		var body struct {
			NodeID string `json:"node_id"`
			Answer string `json:"answer"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			http.Error(w, "Invalid body", http.StatusBadRequest)
			return
		}
		ok := wsHub.AnswerHuman(body.NodeID, body.Answer)
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		_ = json.NewEncoder(w).Encode(map[string]any{"ok": ok})
	})

	var (
		runningCmdsMu sync.Mutex
		runningCmds   = make(map[string]*exec.Cmd)
	)

	// Helper to launch session
	launchSession := func(sessionID, task, projectID, teamID string) {
		args := []string{
			cfg.PHPHarness,
			"run",
			fmt.Sprintf("--session-id=%s", sessionID),
			fmt.Sprintf("--task=%s", task),
			fmt.Sprintf("--project=%s", projectID),
			fmt.Sprintf("--team=%s", teamID),
		}

		activeProf := profilesStore.getActive()
		curSt := settingsStore.Get()
		log.Printf("[Session] Launching PHP harness task (Session: %s, Project: %s, Team: %s)\n", sessionID, projectID, teamID)

		wsHub.Broadcast(ws.Event{
			Event:     "session.started",
			SessionID: sessionID,
			Timestamp: time.Now().UTC().Format(time.RFC3339Nano),
			Data: map[string]any{
				"session_id": sessionID,
				"task":       task,
				"project_id": projectID,
				"team_id":    teamID,
			},
		})

		cmd := exec.Command(cfg.PHPBin, args...)
		cmd.Stdout = os.Stdout
		cmd.Stderr = os.Stderr

		cmd.Env = append(os.Environ(),
			"TASK_PROMPT="+task,
			"SESSION_ID="+sessionID,
			"PROJECT_ID="+projectID,
			"TEAM_ID="+teamID,
			"SUBAGENT_MAX_STEPS="+strconv.Itoa(curSt.SubAgentMaxSteps),
			"ROOT_MAX_STEPS="+strconv.Itoa(curSt.RootMaxSteps),
			"SUBAGENT_MAX_TOKENS="+strconv.Itoa(curSt.SubAgentMaxTokens),
			"LOOP_PROTECTION_ENABLED="+strconv.FormatBool(curSt.LoopProtectionEnabled),
			"LOOP_DETECTION_THRESHOLD="+strconv.Itoa(curSt.LoopDetectionThreshold),
			"LLM_MAX_RETRIES="+strconv.Itoa(curSt.LLMMaxRetries),
			"LLM_RETRY_DELAY_SEC="+strconv.Itoa(curSt.LLMRetryDelaySec),
			"GLOBAL_SYSTEM_PROMPT="+curSt.GlobalSystemPrompt,
		)

		if activeProf != nil {
			cmd.Env = append(cmd.Env,
				"OPENAI_BASE_URL="+activeProf.BaseURL,
				"OPENAI_API_KEY="+activeProf.APIKey,
				"DEFAULT_MODEL="+activeProf.DefaultModel,
			)
		}

		runningCmdsMu.Lock()
		runningCmds[sessionID] = cmd
		runningCmdsMu.Unlock()

		defer func() {
			runningCmdsMu.Lock()
			delete(runningCmds, sessionID)
			runningCmdsMu.Unlock()
		}()

		if err := cmd.Run(); err != nil {
			log.Printf("[Session] PHP harness run error: %v\n", err)
			wsHub.Broadcast(ws.Event{
				Event:     "session.failed",
				SessionID: sessionID,
				Timestamp: time.Now().UTC().Format(time.RFC3339Nano),
				Data: map[string]any{
					"session_id": sessionID,
					"error":      err.Error(),
				},
			})
		} else {
			log.Printf("[Session] PHP harness finished successfully for session %s\n", sessionID)
			wsHub.Broadcast(ws.Event{
				Event:     "session.completed",
				SessionID: sessionID,
				Timestamp: time.Now().UTC().Format(time.RFC3339Nano),
				Data: map[string]any{
					"session_id": sessionID,
				},
			})
		}
	}

	// Helper to send followup message to session manager or node
	launchSessionMessage := func(sessionID, nodeID, message string) {
		args := []string{
			cfg.PHPHarness,
			"session:message",
			fmt.Sprintf("--session-id=%s", sessionID),
			fmt.Sprintf("--message=%s", message),
		}
		if nodeID != "" {
			args = append(args, fmt.Sprintf("--node-id=%s", nodeID))
		}

		activeProf := profilesStore.getActive()
		curSt := settingsStore.Get()
		log.Printf("[Session] Sending followup message (Session: %s, Node: %s)\n", sessionID, nodeID)

		wsHub.Broadcast(ws.Event{
			Event:     "session.started",
			SessionID: sessionID,
			Timestamp: time.Now().UTC().Format(time.RFC3339Nano),
			Data: map[string]any{
				"session_id": sessionID,
				"node_id":    nodeID,
				"message":    message,
			},
		})

		cmd := exec.Command(cfg.PHPBin, args...)
		cmd.Stdout = os.Stdout
		cmd.Stderr = os.Stderr

		cmd.Env = append(os.Environ(),
			"TASK_PROMPT="+message,
			"SESSION_ID="+sessionID,
			"SUBAGENT_MAX_STEPS="+strconv.Itoa(curSt.SubAgentMaxSteps),
			"ROOT_MAX_STEPS="+strconv.Itoa(curSt.RootMaxSteps),
			"SUBAGENT_MAX_TOKENS="+strconv.Itoa(curSt.SubAgentMaxTokens),
			"LOOP_PROTECTION_ENABLED="+strconv.FormatBool(curSt.LoopProtectionEnabled),
			"LOOP_DETECTION_THRESHOLD="+strconv.Itoa(curSt.LoopDetectionThreshold),
			"LLM_MAX_RETRIES="+strconv.Itoa(curSt.LLMMaxRetries),
			"LLM_RETRY_DELAY_SEC="+strconv.Itoa(curSt.LLMRetryDelaySec),
			"GLOBAL_SYSTEM_PROMPT="+curSt.GlobalSystemPrompt,
		)

		if activeProf != nil {
			cmd.Env = append(cmd.Env,
				"OPENAI_BASE_URL="+activeProf.BaseURL,
				"OPENAI_API_KEY="+activeProf.APIKey,
				"DEFAULT_MODEL="+activeProf.DefaultModel,
			)
		}

		runningCmdsMu.Lock()
		runningCmds[sessionID] = cmd
		runningCmdsMu.Unlock()

		defer func() {
			runningCmdsMu.Lock()
			delete(runningCmds, sessionID)
			runningCmdsMu.Unlock()
		}()

		if err := cmd.Run(); err != nil {
			log.Printf("[Session] PHP harness followup error: %v\n", err)
			wsHub.Broadcast(ws.Event{
				Event:     "session.failed",
				SessionID: sessionID,
				Timestamp: time.Now().UTC().Format(time.RFC3339Nano),
				Data: map[string]any{
					"session_id": sessionID,
					"error":      err.Error(),
				},
			})
		} else {
			log.Printf("[Session] Followup completed for session: %s\n", sessionID)
			wsHub.Broadcast(ws.Event{
				Event:     "session.completed",
				SessionID: sessionID,
				Timestamp: time.Now().UTC().Format(time.RFC3339Nano),
				Data: map[string]any{
					"session_id": sessionID,
				},
			})
		}
	}

	// Background Scheduler for delayed tasks
	go func() {
		ticker := time.NewTicker(5 * time.Second)
		defer ticker.Stop()
		for range ticker.C {
			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "tasks:scheduled")
			if err != nil {
				continue
			}
			var tasks []map[string]any
			if json.Unmarshal(out, &tasks) != nil {
				continue
			}
			nowUTC := time.Now().UTC().Format("2006-01-02 15:04:05")
			for _, t := range tasks {
				status, _ := t["status"].(string)
				runAt, _ := t["run_at"].(string)
				if status == "pending" && runAt != "" && runAt <= nowUTC {
					taskID, _ := t["id"].(string)
					taskText, _ := t["task"].(string)
					projectID, _ := t["project_id"].(string)
					teamID, _ := t["team_id"].(string)
					sessionID := fmt.Sprintf("sched_%d", time.Now().UnixNano())

					log.Printf("[Scheduler] Triggering scheduled task %s (Session %s)\n", taskID, sessionID)
					// Cancel from pending list so it won't re-trigger
					_, _ = runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "tasks:cancel", taskID)

					go launchSession(sessionID, taskText, projectID, teamID)
				}
			}
		}
	}()

	// API Sessions List & Delete (History)
	mux.HandleFunc("/api/sessions", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		if r.Method == http.MethodGet {
			args := []string{"sessions:list"}
			projectID := r.URL.Query().Get("project_id")
			if projectID != "" {
				args = append(args, fmt.Sprintf("--project_id=%s", projectID))
			}
			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, args...)
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		if r.Method == http.MethodDelete {
			id := r.URL.Query().Get("id")
			action := r.URL.Query().Get("action")
			projectID := r.URL.Query().Get("project_id")
			days := r.URL.Query().Get("days")
			args := []string{"sessions:delete"}
			if action != "" {
				args = append(args, fmt.Sprintf("--action=%s", action))
			}
			if id != "" {
				args = append(args, fmt.Sprintf("--id=%s", id))
			}
			if projectID != "" {
				args = append(args, fmt.Sprintf("--project_id=%s", projectID))
			}
			if days != "" {
				args = append(args, fmt.Sprintf("--days=%s", days))
			}

			if len(args) == 1 {
				http.Error(w, "id or action required", http.StatusBadRequest)
				return
			}

			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, args...)
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}

			// Clean up NDJSON log directories
			if id != "" {
				for _, sid := range strings.Split(id, ",") {
					sid = strings.TrimSpace(sid)
					if sid != "" {
						_ = os.RemoveAll(filepath.Join(cfg.LogDir, sid))
					}
				}
			} else if action == "clear_all" {
				if entries, err := os.ReadDir(cfg.LogDir); err == nil {
					for _, e := range entries {
						_ = os.RemoveAll(filepath.Join(cfg.LogDir, e.Name()))
					}
				}
			}

			_, _ = w.Write(out)
			return
		}

		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
	})

	// API Session Graph (Nodes & Trace)
	mux.HandleFunc("/api/session/graph", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		id := r.URL.Query().Get("id")
		if id == "" {
			http.Error(w, "id parameter required", http.StatusBadRequest)
			return
		}
		out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "session:graph", id)
		if err != nil {
			http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
			return
		}
		_, _ = w.Write(out)
	})

	// API Scheduled Tasks
	mux.HandleFunc("/api/tasks/scheduled", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		if r.Method == http.MethodGet {
			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "tasks:scheduled")
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		if r.Method == http.MethodPost {
			body, err := io.ReadAll(r.Body)
			if err != nil {
				http.Error(w, "Invalid body: "+err.Error(), http.StatusBadRequest)
				return
			}
			out, runErr := runPHPCommandWithStdin(cfg.PHPBin, cfg.PHPHarness, body, "tasks:schedule")
			if runErr != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", runErr, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		if r.Method == http.MethodDelete {
			id := r.URL.Query().Get("id")
			if id == "" {
				http.Error(w, "id parameter required", http.StatusBadRequest)
				return
			}
			out, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "tasks:cancel", id)
			if err != nil {
				http.Error(w, fmt.Sprintf("PHP error: %v (%s)", err, string(out)), http.StatusInternalServerError)
				return
			}
			_, _ = w.Write(out)
			return
		}

		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
	})

	// API Start Session
	mux.HandleFunc("/api/session/start", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}
		var body struct {
			SessionID string `json:"session_id"`
			Task      string `json:"task"`
			ProjectID string `json:"project_id"`
			TeamID    string `json:"team_id"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			http.Error(w, "Invalid body: "+err.Error(), http.StatusBadRequest)
			return
		}
		if body.SessionID == "" {
			body.SessionID = fmt.Sprintf("sess_%d", time.Now().UnixNano())
		}
		if body.Task == "" {
			body.Task = "Review workspace and report status"
		}
		if body.ProjectID == "" {
			body.ProjectID = "proj_default"
		}

		if body.TeamID == "" {
			projOut, err := runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "projects:list")
			if err == nil {
				var projects []map[string]any
				if json.Unmarshal(projOut, &projects) == nil {
					for _, p := range projects {
						if p["id"] == body.ProjectID {
							if dTeam, ok := p["default_team_id"].(string); ok && dTeam != "" {
								body.TeamID = dTeam
							}
							break
						}
					}
				}
			}
			if body.TeamID == "" {
				body.TeamID = "team_core"
			}
		}

		go launchSession(body.SessionID, body.Task, body.ProjectID, body.TeamID)

		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"status":     "started",
			"session_id": body.SessionID,
			"project_id": body.ProjectID,
			"team_id":    body.TeamID,
		})
	})

	// API Send Followup Message to Session Manager / Node
	mux.HandleFunc("/api/session/message", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}
		var body struct {
			SessionID string `json:"session_id"`
			NodeID    string `json:"node_id"`
			Message   string `json:"message"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			http.Error(w, "Invalid body: "+err.Error(), http.StatusBadRequest)
			return
		}
		if body.SessionID == "" || strings.TrimSpace(body.Message) == "" {
			http.Error(w, "session_id and message are required", http.StatusBadRequest)
			return
		}

		go launchSessionMessage(body.SessionID, strings.TrimSpace(body.NodeID), strings.TrimSpace(body.Message))

		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"status":     "started",
			"session_id": body.SessionID,
			"node_id":    body.NodeID,
		})
	})

	// API Stop Session
	mux.HandleFunc("/api/session/stop", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}
		var body struct {
			SessionID string `json:"session_id"`
		}
		_ = json.NewDecoder(r.Body).Decode(&body)

		runningCmdsMu.Lock()
		var targetCmd *exec.Cmd
		var targetID string
		if body.SessionID != "" {
			if cmd, ok := runningCmds[body.SessionID]; ok {
				targetCmd = cmd
				targetID = body.SessionID
			}
		} else {
			for id, cmd := range runningCmds {
				targetCmd = cmd
				targetID = id
				break
			}
		}
		if targetCmd != nil {
			delete(runningCmds, targetID)
		}
		runningCmdsMu.Unlock()

		if targetCmd != nil && targetCmd.Process != nil {
			log.Printf("[Session] Stopping session %s (PID %d)...\n", targetID, targetCmd.Process.Pid)
			_ = targetCmd.Process.Kill()

			_, _ = runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "session:stop", targetID)

			wsHub.Broadcast(ws.Event{
				Event:     "session.failed",
				SessionID: targetID,
				Timestamp: time.Now().UTC().Format(time.RFC3339Nano),
				Data: map[string]any{
					"session_id": targetID,
					"error":      "Stopped by user",
				},
			})

			w.Header().Set("Content-Type", "application/json; charset=utf-8")
			_ = json.NewEncoder(w).Encode(map[string]any{
				"ok":         true,
				"session_id": targetID,
				"status":     "stopped",
			})
			return
		}

		if body.SessionID != "" {
			_, _ = runPHPCommand(cfg.PHPBin, cfg.PHPHarness, "session:stop", body.SessionID)
			wsHub.Broadcast(ws.Event{
				Event:     "session.failed",
				SessionID: body.SessionID,
				Timestamp: time.Now().UTC().Format(time.RFC3339Nano),
				Data: map[string]any{
					"session_id": body.SessionID,
					"error":      "Stopped by user",
				},
			})
		}

		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"ok":      true,
			"message": "No active process found for session",
		})
	})

	// API Filesystem Dirs Explorer
	mux.HandleFunc("/api/filesystem/dirs", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		if r.Method != http.MethodGet {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		reqPath := r.URL.Query().Get("path")
		if reqPath == "" {
			reqPath = cfg.WorkspaceDir
		}
		cleanPath := filepath.Clean(reqPath)

		// Ensure directory exists or fallback to workspace root
		if _, err := os.Stat(cleanPath); os.IsNotExist(err) {
			_ = os.MkdirAll(cleanPath, 0755)
		}

		entries, err := os.ReadDir(cleanPath)
		if err != nil {
			// If reading still fails, fallback to workspace root
			cleanPath = cfg.WorkspaceDir
			entries, _ = os.ReadDir(cleanPath)
		}

		type DirItem struct {
			Name        string `json:"name"`
			Path        string `json:"path"`
			SubdirCount int    `json:"subdir_count"`
		}

		dirs := []DirItem{}
		for _, e := range entries {
			if e.IsDir() && !strings.HasPrefix(e.Name(), ".") {
				subPath := filepath.Join(cleanPath, e.Name())
				subCount := 0
				if subEntries, err := os.ReadDir(subPath); err == nil {
					for _, se := range subEntries {
						if se.IsDir() && !strings.HasPrefix(se.Name(), ".") {
							subCount++
						}
					}
				}
				dirs = append(dirs, DirItem{
					Name:        e.Name(),
					Path:        subPath,
					SubdirCount: subCount,
				})
			}
		}

		if !strings.HasPrefix(cleanPath, cfg.WorkspaceDir) {
			cleanPath = cfg.WorkspaceDir
		}
		parent := filepath.Dir(cleanPath)
		canGoUp := cleanPath != cfg.WorkspaceDir && strings.HasPrefix(parent, cfg.WorkspaceDir)
		_ = json.NewEncoder(w).Encode(map[string]any{
			"current_path": cleanPath,
			"parent_path":  parent,
			"can_go_up":    canGoUp,
			"root_path":    cfg.WorkspaceDir,
			"dirs":         dirs,
		})
	})

	// API Filesystem Mkdir
	mux.HandleFunc("/api/filesystem/mkdir", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		if r.Method != http.MethodPost {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		var body struct {
			Path   string `json:"path"`
			Parent string `json:"parent"`
			Name   string `json:"name"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			http.Error(w, "Invalid body: "+err.Error(), http.StatusBadRequest)
			return
		}

		targetPath := body.Path
		if targetPath == "" && body.Name != "" {
			parent := body.Parent
			if parent == "" {
				parent = cfg.WorkspaceDir
			}
			targetPath = filepath.Join(parent, body.Name)
		}

		if targetPath == "" {
			http.Error(w, "Path or Name is required", http.StatusBadRequest)
			return
		}

		cleanPath := filepath.Clean(targetPath)
		if err := os.MkdirAll(cleanPath, 0755); err != nil {
			http.Error(w, fmt.Sprintf("Failed to create directory %s: %v", cleanPath, err), http.StatusInternalServerError)
			return
		}

		_ = json.NewEncoder(w).Encode(map[string]any{
			"ok":   true,
			"path": cleanPath,
		})
	})

	// Static Web UI File Server with SPA fallback
	fs := http.FileServer(http.Dir(cfg.WebDir))
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/" {
			fullPath := filepath.Join(cfg.WebDir, filepath.Clean(r.URL.Path))
			if _, err := os.Stat(fullPath); os.IsNotExist(err) {
				// Fallback to index.html for SPA routes
				http.ServeFile(w, r, filepath.Join(cfg.WebDir, "index.html"))
				return
			}
		}
		fs.ServeHTTP(w, r)
	})

	httpServer := &http.Server{
		Addr:    ":" + cfg.HTTPPort,
		Handler: mux,
	}

	go func() {
		log.Printf("[HTTP] Server listening on http://0.0.0.0:%s\n", cfg.HTTPPort)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("HTTP server failed: %v", err)
		}
	}()

	// Graceful shutdown
	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit

	log.Println("Shutting down Harness Engine...")
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	_ = httpServer.Shutdown(shutdownCtx)
	log.Println("Harness Engine exited cleanly.")
}
