package runner

import (
	"bytes"
	"context"
	"errors"
	"os/exec"
	"runtime"
	"time"
)

type ExecResult struct {
	ExitCode   int    `json:"exit_code"`
	Stdout     string `json:"stdout"`
	Stderr     string `json:"stderr"`
	DurationMs int64  `json:"duration_ms"`
}

type LocalRunner struct {
	defaultWorkDir string
}

func NewLocalRunner(defaultWorkDir string) *LocalRunner {
	return &LocalRunner{
		defaultWorkDir: defaultWorkDir,
	}
}

// ExecLocal executes command on local host / agent container in given workDir
func (r *LocalRunner) ExecLocal(ctx context.Context, cmdSlice []string, workDir string, timeout time.Duration) (*ExecResult, error) {
	if len(cmdSlice) == 0 {
		return nil, errors.New("command cannot be empty")
	}

	if workDir == "" {
		workDir = r.defaultWorkDir
	}

	if timeout <= 0 {
		timeout = 60 * time.Second
	}

	execCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	var cmd *exec.Cmd
	if len(cmdSlice) == 1 {
		// Single string shell command execution
		if runtime.GOOS == "windows" {
			cmd = exec.CommandContext(execCtx, "cmd.exe", "/c", cmdSlice[0])
		} else {
			cmd = exec.CommandContext(execCtx, "/bin/sh", "-c", cmdSlice[0])
		}
	} else {
		cmd = exec.CommandContext(execCtx, cmdSlice[0], cmdSlice[1:]...)
	}

	cmd.Dir = workDir

	var stdoutBuf, stderrBuf bytes.Buffer
	cmd.Stdout = &stdoutBuf
	cmd.Stderr = &stderrBuf

	startTime := time.Now()
	err := cmd.Run()
	duration := time.Since(startTime)

	result := &ExecResult{
		ExitCode:   0,
		Stdout:     stdoutBuf.String(),
		Stderr:     stderrBuf.String(),
		DurationMs: duration.Milliseconds(),
	}

	if err != nil {
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) {
			result.ExitCode = exitErr.ExitCode()
		} else if errors.Is(execCtx.Err(), context.DeadlineExceeded) {
			result.ExitCode = 124 // Standard timeout exit code
			result.Stderr += "\nCommand timed out"
		} else {
			result.ExitCode = 1
			result.Stderr += "\n" + err.Error()
		}
	}

	return result, nil
}
