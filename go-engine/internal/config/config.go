package config

import (
	"flag"
	"os"
	"path/filepath"
	"strings"
)

type Config struct {
	IPCSocketPath string
	IPCTCPAddr    string
	HTTPPort      string
	WebDir        string
	WorkspaceDir  string
	DataDir       string
	LogDir        string
	OpenAIBaseURL string
	OpenAIKey     string
	DefaultModel  string
	PHPBin        string
	PHPHarness    string
}

func Load() *Config {
	cfg := &Config{}

	flag.StringVar(&cfg.IPCSocketPath, "ipc-socket", getEnv("HARNESS_IPC_SOCKET", "/var/run/harness.sock"), "Path to Unix domain socket for IPC")
	flag.StringVar(&cfg.IPCTCPAddr, "ipc-tcp", getEnv("HARNESS_IPC_TCP", "127.0.0.1:9099"), "Fallback TCP address for IPC (e.g. on Windows)")
	flag.StringVar(&cfg.HTTPPort, "http-port", getEnv("HTTP_PORT", "8080"), "HTTP/WebSocket port")
	flag.StringVar(&cfg.WebDir, "web-dir", getEnv("WEB_DIR", "./frontend/dist"), "Directory with frontend static build")
	flag.StringVar(&cfg.WorkspaceDir, "workspace-dir", getEnv("WORKSPACE_DIR", "./workspace"), "Directory mounted as project workspace")
	flag.StringVar(&cfg.DataDir, "data-dir", getEnv("DATA_DIR", "./data"), "Directory for sqlite database and logs")
	flag.StringVar(&cfg.OpenAIBaseURL, "openai-base-url", getEnv("OPENAI_BASE_URL", "https://api.openai.com/v1"), "OpenAI API Base URL")
	flag.StringVar(&cfg.OpenAIKey, "openai-api-key", getEnv("OPENAI_API_KEY", ""), "OpenAI API Key")
	flag.StringVar(&cfg.DefaultModel, "default-model", getEnv("DEFAULT_MODEL", "gpt-4o"), "Default LLM model")
	flag.StringVar(&cfg.PHPBin, "php-bin", getEnv("PHP_BIN", "php"), "PHP binary path")
	flag.StringVar(&cfg.PHPHarness, "php-harness", getEnv("PHP_HARNESS", "./php-core/bin/harness"), "PHP harness entrypoint")

	// Parse flags only if not already parsed (useful for tests)
	if !flag.Parsed() {
		flag.Parse()
	}

	cfg.LogDir = filepath.Join(cfg.DataDir, "logs")

	return cfg
}

func getEnv(key, defaultVal string) string {
	if val, ok := os.LookupEnv(key); ok && strings.TrimSpace(val) != "" {
		return val
	}
	return defaultVal
}
