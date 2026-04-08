<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Runtime;

/**
 * Multi-binary CLI runner for Doom toolchain commands.
 *
 * Wraps proc_open() with timeout support and output truncation.
 * Manages deutex, gzdoom, chocolate-doom, and dsda-doom binaries.
 */
final class DoomRunner
{
    private const int DEFAULT_TIMEOUT = 60;
    private const int MAX_OUTPUT_BYTES = 65_536;

    /** @var array<string, string> */
    private array $resolvedBinaries = [];

    public function __construct(
        private readonly string $workspacePath = '',
    ) {}

    /**
     * Run a DeuTex command.
     *
     * @param string[] $args
     */
    public function deutex(array $args, ?string $cwd = null, int $timeout = self::DEFAULT_TIMEOUT): DoomResult
    {
        $binary = $this->resolveBinary('deutex');
        if ($binary === '') {
            return new DoomResult(127, '', 'deutex not found. Install with: apt install deutex (Linux) or brew install deutex (macOS)');
        }

        return $this->runBinary($binary, $args, $cwd ?? $this->workspacePath, $timeout);
    }

    /**
     * Launch a source port with the given arguments.
     *
     * @param string[] $args
     */
    public function launchSourcePort(string $port, array $args, int $timeout = 5): DoomResult
    {
        $binary = $this->resolveBinary($port);
        if ($binary === '') {
            return new DoomResult(127, '', sprintf('%s not found. Install a Doom source port to launch builds.', $port));
        }

        // Launch in background — source ports are GUI applications
        $parts = [escapeshellarg($binary)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        $command = implode(' ', $parts) . ' > /dev/null 2>&1 &';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->workspacePath);
        if (!is_resource($process)) {
            return new DoomResult(1, '', sprintf('Failed to launch %s', $port));
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return new DoomResult(0, sprintf('%s launched successfully with args: %s', $port, implode(' ', $args)), '');
    }

    /**
     * Resolve a binary by name using which.
     */
    public function resolveBinary(string $name): string
    {
        if (isset($this->resolvedBinaries[$name])) {
            return $this->resolvedBinaries[$name];
        }

        $which = trim((string) shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null'));

        if ($which !== '' && file_exists($which)) {
            $this->resolvedBinaries[$name] = $which;

            return $which;
        }

        // Check common alternative locations
        $alternatives = match ($name) {
            'gzdoom' => ['/usr/games/gzdoom', '/usr/local/bin/gzdoom', '/snap/bin/gzdoom'],
            'chocolate-doom' => ['/usr/games/chocolate-doom', '/usr/local/bin/chocolate-doom'],
            'dsda-doom' => ['/usr/games/dsda-doom', '/usr/local/bin/dsda-doom'],
            'deutex' => ['/usr/bin/deutex', '/usr/local/bin/deutex'],
            default => [],
        };

        foreach ($alternatives as $path) {
            if (file_exists($path) && is_executable($path)) {
                $this->resolvedBinaries[$name] = $path;

                return $path;
            }
        }

        return '';
    }

    /**
     * Check which toolchain binaries are available.
     *
     * @return array<string, array{available: bool, path: string, version: string}>
     */
    public function checkToolchain(): array
    {
        $tools = ['deutex', 'gzdoom', 'chocolate-doom', 'dsda-doom'];
        $status = [];

        foreach ($tools as $tool) {
            $path = $this->resolveBinary($tool);
            $version = '';

            if ($path !== '') {
                $versionResult = $this->runBinary($path, ['--version'], $this->workspacePath, 5);
                $version = trim($versionResult->stdout ?: $versionResult->stderr);
                // Take only the first line of version output
                $firstLine = strtok($version, "\n");
                $version = $firstLine !== false ? $firstLine : $version;
            }

            $status[$tool] = [
                'available' => $path !== '',
                'path' => $path,
                'version' => $version,
            ];
        }

        return $status;
    }

    /**
     * @param string[] $args
     */
    private function runBinary(string $binary, array $args, string $cwd, int $timeout): DoomResult
    {
        $parts = [escapeshellarg($binary)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        $command = implode(' ', $parts);

        return $this->execute($command, $cwd, $timeout);
    }

    private function execute(string $command, string $cwd, int $timeout): DoomResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return new DoomResult(1, '', 'Failed to start process');
        }

        fclose($pipes[0]);

        // Set non-blocking mode
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = microtime(true);

        while (true) {
            $status = proc_get_status($process);

            $outChunk = (string) fread($pipes[1], 8192);
            $errChunk = (string) fread($pipes[2], 8192);

            if ($outChunk !== '') {
                $stdout .= $outChunk;
            }
            if ($errChunk !== '') {
                $stderr .= $errChunk;
            }

            // Truncate if too large
            if (strlen($stdout) > self::MAX_OUTPUT_BYTES) {
                $stdout = substr($stdout, 0, self::MAX_OUTPUT_BYTES) . "\n... [output truncated]";
                break;
            }

            if (!$status['running']) {
                // Read any remaining output
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }

            if ($timeout > 0 && (microtime(true) - $startTime) >= $timeout) {
                proc_terminate($process, 15);
                usleep(100_000);
                $termStatus = proc_get_status($process);
                if ($termStatus['running']) {
                    proc_terminate($process, 9);
                }
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new DoomResult(124, $stdout, 'Command timed out after ' . $timeout . ' seconds');
            }

            usleep(10_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // proc_close may return -1 if already closed, use status from proc_get_status
        if ($exitCode === -1 && !$status['running']) {
            $exitCode = $status['exitcode'];
        }

        return new DoomResult($exitCode, $stdout, $stderr);
    }
}
