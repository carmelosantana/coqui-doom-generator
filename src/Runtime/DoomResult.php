<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Runtime;

use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Value object representing the result of a CLI command execution.
 */
final readonly class DoomResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    public function output(): string
    {
        $combined = trim($this->stdout);
        $err = trim($this->stderr);

        if ($err !== '') {
            $combined .= ($combined !== '' ? "\n" : '') . $err;
        }

        return $combined;
    }

    public function toToolResult(): ToolResult
    {
        $output = $this->output();

        if ($this->succeeded()) {
            return ToolResult::success($output !== '' ? $output : 'OK');
        }

        return ToolResult::error($output !== '' ? $output : 'Command failed with exit code ' . $this->exitCode);
    }
}
