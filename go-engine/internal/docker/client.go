package docker

import (
	"bytes"
	"context"
	"fmt"
	"time"

	"github.com/docker/docker/api/types"
	"github.com/docker/docker/client"
	"github.com/docker/docker/pkg/stdcopy"
)

type ExecResult struct {
	ExitCode   int    `json:"exit_code"`
	Stdout     string `json:"stdout"`
	Stderr     string `json:"stderr"`
	DurationMs int64  `json:"duration_ms"`
}

type DockerEngine struct {
	cli *client.Client
}

func NewDockerEngine() (*DockerEngine, error) {
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		return nil, fmt.Errorf("failed to create docker client: %w", err)
	}

	return &DockerEngine{cli: cli}, nil
}

// ExecInContainer executes command inside specified target container
func (d *DockerEngine) ExecInContainer(ctx context.Context, containerID string, cmd []string, workDir string, timeout time.Duration) (*ExecResult, error) {
	if d.cli == nil {
		return nil, fmt.Errorf("docker client is not initialized")
	}

	if containerID == "" {
		return nil, fmt.Errorf("container ID or name is required")
	}

	if len(cmd) == 0 {
		return nil, fmt.Errorf("command slice cannot be empty")
	}

	if timeout <= 0 {
		timeout = 120 * time.Second
	}

	execCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	execConfig := types.ExecConfig{
		AttachStdout: true,
		AttachStderr: true,
		WorkingDir:   workDir,
		Cmd:          cmd,
	}

	execCreateResp, err := d.cli.ContainerExecCreate(execCtx, containerID, execConfig)
	if err != nil {
		return nil, fmt.Errorf("failed to create container exec instance: %w", err)
	}

	execID := execCreateResp.ID

	attachResp, err := d.cli.ContainerExecAttach(execCtx, execID, types.ExecStartCheck{})
	if err != nil {
		return nil, fmt.Errorf("failed to attach to container exec instance: %w", err)
	}
	defer attachResp.Close()

	var stdoutBuf, stderrBuf bytes.Buffer
	startTime := time.Now()

	copyErrCh := make(chan error, 1)
	go func() {
		_, copyErr := stdcopy.StdCopy(&stdoutBuf, &stderrBuf, attachResp.Reader)
		copyErrCh <- copyErr
	}()

	select {
	case <-execCtx.Done():
		return &ExecResult{
			ExitCode:   124,
			Stdout:     stdoutBuf.String(),
			Stderr:     stderrBuf.String() + "\nDocker exec timed out",
			DurationMs: time.Since(startTime).Milliseconds(),
		}, nil
	case copyErr := <-copyErrCh:
		if copyErr != nil {
			return nil, fmt.Errorf("error reading exec output: %w", copyErr)
		}
	}

	inspectResp, err := d.cli.ContainerExecInspect(execCtx, execID)
	if err != nil {
		return nil, fmt.Errorf("failed to inspect container exec instance: %w", err)
	}

	return &ExecResult{
		ExitCode:   inspectResp.ExitCode,
		Stdout:     stdoutBuf.String(),
		Stderr:     stderrBuf.String(),
		DurationMs: time.Since(startTime).Milliseconds(),
	}, nil
}
