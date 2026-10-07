package llm

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"strings"
	"sync"
	"time"
)

type FunctionDefinition struct {
	Name        string         `json:"name"`
	Description string         `json:"description,omitempty"`
	Parameters  map[string]any `json:"parameters,omitempty"`
}

type ToolDefinition struct {
	Type     string             `json:"type"`
	Function FunctionDefinition `json:"function"`
}

type ToolCallFunction struct {
	Name      string `json:"name"`
	Arguments string `json:"arguments"`
}

type ToolCall struct {
	Index    *int             `json:"index,omitempty"`
	ID       string           `json:"id"`
	Type     string           `json:"type"`
	Function ToolCallFunction `json:"function"`
}

type ChatMessage struct {
	Role       string     `json:"role"`
	Content    string     `json:"content"`
	Name       string     `json:"name,omitempty"`
	ToolCallID string     `json:"tool_call_id,omitempty"`
	ToolCalls  []ToolCall `json:"tool_calls,omitempty"`
}

type ChatRequest struct {
	Model        string           `json:"model"`
	Messages     []ChatMessage    `json:"messages"`
	Tools        []ToolDefinition `json:"tools,omitempty"`
	Temperature  *float64         `json:"temperature,omitempty"`
	MaxTokens    *int             `json:"max_tokens,omitempty"`
	Stream       bool             `json:"stream"`
	SessionID    string           `json:"session_id,omitempty"`
	NodeID       string           `json:"node_id,omitempty"`
	LLMProfileID string           `json:"llm_profile_id,omitempty"`
}

type StreamDelta struct {
	Role             string     `json:"role,omitempty"`
	Content          string     `json:"content,omitempty"`
	ReasoningContent string     `json:"reasoning_content,omitempty"`
	Thought          string     `json:"thought,omitempty"`
	ToolCalls        []ToolCall `json:"tool_calls,omitempty"`
}

type StreamChoice struct {
	Index        int         `json:"index"`
	Delta        StreamDelta `json:"delta"`
	FinishReason *string     `json:"finish_reason"`
}

type TokenUsage struct {
	PromptTokens     int `json:"prompt_tokens"`
	CompletionTokens int `json:"completion_tokens"`
	TotalTokens      int `json:"total_tokens"`
}

type StreamEvent struct {
	ID      string         `json:"id"`
	Object  string         `json:"object"`
	Created int64          `json:"created"`
	Model   string         `json:"model"`
	Choices []StreamChoice `json:"choices"`
	Usage   *TokenUsage    `json:"usage,omitempty"`
}

type ChatResponse struct {
	Content          string     `json:"content"`
	ReasoningContent string     `json:"reasoning_content,omitempty"`
	ToolCalls        []ToolCall `json:"tool_calls,omitempty"`
	FinishReason     string     `json:"finish_reason"`
	TTFTMs           int64      `json:"ttft_ms"`
	LatencyMs        int64      `json:"latency_ms"`
	PromptTokens     int        `json:"prompt_tokens"`
	CompletionTokens int        `json:"completion_tokens"`
	TotalTokens      int        `json:"total_tokens"`
}

type Client struct {
	baseURL       string
	apiKey        string
	httpClient    *http.Client
	maxRetries    int
	retryDelaySec int
	mu            sync.RWMutex
}

func NewClient(baseURL, apiKey string) *Client {
	if baseURL == "" {
		baseURL = "https://api.openai.com/v1"
	}
	baseURL = strings.TrimRight(baseURL, "/")

	return &Client{
		baseURL:       baseURL,
		apiKey:        apiKey,
		maxRetries:    3,
		retryDelaySec: 3,
		httpClient: &http.Client{
			Timeout: 0, // Streaming requests require no overall client timeout; use context
		},
	}
}

func (c *Client) SetRetryConfig(maxRetries int, retryDelaySec int) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if maxRetries < 0 {
		maxRetries = 0
	}
	if retryDelaySec < 1 {
		retryDelaySec = 1
	}
	c.maxRetries = maxRetries
	c.retryDelaySec = retryDelaySec
}

func (c *Client) GetRetryConfig() (int, int) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.maxRetries, c.retryDelaySec
}

func (c *Client) UpdateConfig(baseURL, apiKey string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if baseURL != "" {
		c.baseURL = strings.TrimRight(baseURL, "/")
	}
	if apiKey != "" {
		c.apiKey = apiKey
	}
}

func (c *Client) GetBaseURL() string {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.baseURL
}

// ChatStream initiates streaming LLM chat completion with retry and pause mechanism
func (c *Client) ChatStream(
	ctx context.Context,
	req ChatRequest,
	onChunk func(deltaText string, reasoningText string, toolCalls []ToolCall) error,
) (*ChatResponse, error) {
	req.Stream = true

	// Build clean outbound payload compatible with all OpenAI-compliant gateways (Gemini, Claude, Ollama, DeepSeek)
	var cleanMessages []map[string]any
	for _, m := range req.Messages {
		cm := map[string]any{
			"role": m.Role,
		}
		if m.Content != "" {
			cm["content"] = m.Content
		} else if m.Role != "assistant" || len(m.ToolCalls) == 0 {
			cm["content"] = ""
		}

		if m.Name != "" {
			cm["name"] = m.Name
		}
		if m.ToolCallID != "" {
			cm["tool_call_id"] = m.ToolCallID
		}
		if len(m.ToolCalls) > 0 {
			var cleanToolCalls []map[string]any
			for _, tc := range m.ToolCalls {
				argsStr := strings.TrimSpace(tc.Function.Arguments)
				if argsStr == "" || argsStr == "[]" || argsStr == "null" {
					argsStr = "{}"
				}
				if strings.HasPrefix(argsStr, "[") {
					argsStr = "{}"
				}
				cleanToolCalls = append(cleanToolCalls, map[string]any{
					"id":   tc.ID,
					"type": "function",
					"function": map[string]any{
						"name":      tc.Function.Name,
						"arguments": argsStr,
					},
				})
			}
			cm["tool_calls"] = cleanToolCalls
		}
		cleanMessages = append(cleanMessages, cm)
	}

	outbound := map[string]any{
		"model":    req.Model,
		"messages": cleanMessages,
		"stream":   true,
	}
	if req.Temperature != nil {
		outbound["temperature"] = *req.Temperature
	}
	if req.MaxTokens != nil {
		outbound["max_tokens"] = *req.MaxTokens
	}
	if len(req.Tools) > 0 {
		var cleanTools []map[string]any
		for _, t := range req.Tools {
			params := t.Function.Parameters
			if params == nil {
				params = map[string]any{
					"type":       "object",
					"properties": map[string]any{},
				}
			}
			cleanTools = append(cleanTools, map[string]any{
				"type": "function",
				"function": map[string]any{
					"name":        t.Function.Name,
					"description": t.Function.Description,
					"parameters":  params,
				},
			})
		}
		outbound["tools"] = cleanTools
	}

	reqBody, err := json.Marshal(outbound)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal chat request: %w", err)
	}

	c.mu.RLock()
	currentBaseURL := c.baseURL
	currentAPIKey := c.apiKey
	maxRetries := c.maxRetries
	retryDelaySec := c.retryDelaySec
	c.mu.RUnlock()

	if maxRetries < 0 {
		maxRetries = 0
	}
	if retryDelaySec < 1 {
		retryDelaySec = 1
	}
	retryDelay := time.Duration(retryDelaySec) * time.Second

	url := fmt.Sprintf("%s/chat/completions", currentBaseURL)

	var (
		lastErr                 error
		firstTokenReceived      bool
		ttft                    time.Duration
		contentBuilder          strings.Builder
		reasoningContentBuilder strings.Builder
		toolCallsMap            = make(map[int]*ToolCall)
		finishReason            string
		usage                   *TokenUsage
		startTime               time.Time
		totalLatency            time.Duration
	)

	for attempt := 0; attempt <= maxRetries; attempt++ {
		if attempt > 0 {
			log.Printf("[LLM Retry] Attempt %d/%d paused for %v after error: %v\n", attempt, maxRetries, retryDelay, lastErr)
			select {
			case <-ctx.Done():
				return nil, fmt.Errorf("context canceled during LLM retry: %w", ctx.Err())
			case <-time.After(retryDelay):
			}
		}

		httpReq, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(reqBody))
		if err != nil {
			return nil, fmt.Errorf("failed to create http request: %w", err)
		}

		httpReq.Header.Set("Content-Type", "application/json")
		httpReq.Header.Set("Accept", "text/event-stream")
		if currentAPIKey != "" {
			httpReq.Header.Set("Authorization", fmt.Sprintf("Bearer %s", currentAPIKey))
		}

		startTime = time.Now()
		resp, err := c.httpClient.Do(httpReq)
		if err != nil {
			lastErr = fmt.Errorf("http request failed: %w", err)
			continue
		}

		if resp.StatusCode != http.StatusOK {
			bodyBytes, _ := io.ReadAll(resp.Body)
			resp.Body.Close()
			lastErr = fmt.Errorf("LLM API returned status %d: %s", resp.StatusCode, string(bodyBytes))
			continue
		}

		// Reset buffers for reading response
		firstTokenReceived = false
		contentBuilder.Reset()
		reasoningContentBuilder.Reset()
		toolCallsMap = make(map[int]*ToolCall)
		finishReason = ""
		usage = nil

		reader := bufio.NewReader(resp.Body)
		streamErr := func() error {
			defer resp.Body.Close()
			for {
				line, readErr := reader.ReadString('\n')
				if readErr != nil && !errors.Is(readErr, io.EOF) {
					return fmt.Errorf("error reading SSE stream: %w", readErr)
				}

				line = strings.TrimSpace(line)
				if strings.HasPrefix(line, "data: ") {
					data := strings.TrimPrefix(line, "data: ")
					if data == "[DONE]" {
						break
					}

					var event StreamEvent
					if err := json.Unmarshal([]byte(data), &event); err != nil {
						// Some engines output malformed chunk, continue
						continue
					}

					if event.Usage != nil {
						usage = event.Usage
					}

					if len(event.Choices) > 0 {
						choice := event.Choices[0]
						if choice.FinishReason != nil {
							finishReason = *choice.FinishReason
						}

						deltaText := choice.Delta.Content
						reasoningDelta := choice.Delta.ReasoningContent
						if reasoningDelta == "" {
							reasoningDelta = choice.Delta.Thought
						}

						if (deltaText != "" || reasoningDelta != "") && !firstTokenReceived {
							firstTokenReceived = true
							ttft = time.Since(startTime)
						}

						if deltaText != "" {
							contentBuilder.WriteString(deltaText)
						}
						if reasoningDelta != "" {
							reasoningContentBuilder.WriteString(reasoningDelta)
						}

						// Accumulate tool calls without duplicating function names
						for _, tc := range choice.Delta.ToolCalls {
							idx := 0
							if tc.Index != nil {
								idx = *tc.Index
							}

							existing, ok := toolCallsMap[idx]
							if !ok {
								toolCallsMap[idx] = &ToolCall{
									ID:   tc.ID,
									Type: tc.Type,
									Function: ToolCallFunction{
										Name:      tc.Function.Name,
										Arguments: tc.Function.Arguments,
									},
								}
							} else {
								if tc.ID != "" && existing.ID == "" {
									existing.ID = tc.ID
								}
								if tc.Type != "" {
									existing.Type = tc.Type
								}
								if tc.Function.Name != "" {
									if existing.Function.Name == "" {
										existing.Function.Name = tc.Function.Name
									} else if existing.Function.Name == tc.Function.Name {
										// Repeated name from upstream chunk, ignore duplicate
									} else if strings.HasPrefix(tc.Function.Name, existing.Function.Name) {
										// Growing full name
										existing.Function.Name = tc.Function.Name
									} else if !strings.Contains(existing.Function.Name, tc.Function.Name) {
										// Fragment delta
										existing.Function.Name += tc.Function.Name
									}
								}
								if tc.Function.Arguments != "" {
									existing.Function.Arguments += tc.Function.Arguments
								}
							}
						}

						if onChunk != nil {
							if err := onChunk(deltaText, reasoningDelta, choice.Delta.ToolCalls); err != nil {
								return fmt.Errorf("onChunk callback failed: %w", err)
							}
						}
					}
				}

				if readErr != nil {
					break
				}
			}
			return nil
		}()

		if streamErr != nil {
			if !firstTokenReceived && attempt < maxRetries {
				lastErr = streamErr
				continue
			}
			return nil, streamErr
		}

		totalLatency = time.Since(startTime)
		lastErr = nil
		break
	}

	if lastErr != nil {
		return nil, fmt.Errorf("LLM API request failed after %d retries: %w", maxRetries, lastErr)
	}

	if !firstTokenReceived {
		ttft = totalLatency
	}

	var finalToolCalls []ToolCall
	for i := 0; i < len(toolCallsMap); i++ {
		if tc, ok := toolCallsMap[i]; ok {
			finalToolCalls = append(finalToolCalls, *tc)
		}
	}

	fullContent := contentBuilder.String()
	reasoningContent := reasoningContentBuilder.String()

	// Extract <think>...</think> block if model formatted reasoning as tags inside content
	if strings.Contains(fullContent, "<think>") && strings.Contains(fullContent, "</think>") {
		startIdx := strings.Index(fullContent, "<think>")
		endIdx := strings.Index(fullContent, "</think>")
		if endIdx > startIdx {
			extracted := fullContent[startIdx+len("<think>") : endIdx]
			if reasoningContent == "" {
				reasoningContent = strings.TrimSpace(extracted)
			} else {
				reasoningContent = strings.TrimSpace(reasoningContent + "\n" + extracted)
			}
			fullContent = strings.TrimSpace(fullContent[:startIdx] + fullContent[endIdx+len("</think>"):])
		}
	} else if strings.HasPrefix(fullContent, "<think>") && !strings.Contains(fullContent, "</think>") {
		reasoningContent = strings.TrimSpace(strings.TrimPrefix(fullContent, "<think>"))
		fullContent = ""
	}

	res := &ChatResponse{
		Content:          fullContent,
		ReasoningContent: reasoningContent,
		ToolCalls:        finalToolCalls,
		FinishReason:     finishReason,
		TTFTMs:           ttft.Milliseconds(),
		LatencyMs:        totalLatency.Milliseconds(),
	}

	if usage != nil {
		res.PromptTokens = usage.PromptTokens
		res.CompletionTokens = usage.CompletionTokens
		res.TotalTokens = usage.TotalTokens
	} else {
		// Heuristic approximation if usage was not returned in SSE
		res.CompletionTokens = len(strings.Fields(res.Content)) * 4 / 3
		res.PromptTokens = len(req.Messages) * 20
		res.TotalTokens = res.PromptTokens + res.CompletionTokens
	}

	return res, nil
}
