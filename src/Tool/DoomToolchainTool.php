<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitDoomGenerator\Asset\IwadManager;
use CarmeloSantana\CoquiToolkitDoomGenerator\Exception\DoomGeneratorException;
use CarmeloSantana\CoquiToolkitDoomGenerator\Runtime\DependencyChecker;
use CarmeloSantana\CoquiToolkitDoomGenerator\Runtime\DoomRunner;

/**
 * Check toolchain availability, install Freedoom, and register WADs.
 */
final readonly class DoomToolchainTool
{
    public function __construct(
        private DoomRunner $runner,
        private IwadManager $iwads,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'doom_toolchain',
            description: 'Manage the Doom modding toolchain. Check which tools are installed (DeuTex, GZDoom, etc.), download Freedoom IWADs, register user-owned IWADs, or list available IWADs.',
            parameters: [
                new EnumParameter('action', 'Action to perform.', ['status', 'install_freedoom', 'register_iwad', 'list_iwads'], required: true),
                new StringParameter('path', 'Path to IWAD file (for register_iwad action).', required: false),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? '');

        return match ($action) {
            'status' => $this->status(),
            'install_freedoom' => $this->installFreedoom(),
            'register_iwad' => $this->registerIwad($input),
            'list_iwads' => $this->listIwads(),
            default => ToolResult::error(sprintf('Unknown doom_toolchain action: "%s". Use: status, install_freedoom, register_iwad, list_iwads.', $action)),
        };
    }

    private function status(): ToolResult
    {
        $toolchain = $this->runner->checkToolchain();
        $iwads = $this->iwads->listIwads();

        $report = DependencyChecker::formatReport($toolchain);

        $output = [
            'toolchain' => $toolchain,
            'install_report' => $report,
            'iwads' => $iwads,
            'iwad_count' => count($iwads),
        ];

        return ToolResult::success(json_encode($output, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }

    private function installFreedoom(): ToolResult
    {
        try {
            $downloaded = $this->iwads->downloadFreedoom();
        } catch (DoomGeneratorException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success(json_encode([
            'installed' => true,
            'freedoom_files' => $downloaded,
            'note' => 'Freedoom IWADs are now available. These are free-content replacements for the original Doom WADs, licensed under BSD.',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function registerIwad(array $input): ToolResult
    {
        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return ToolResult::error('The "path" parameter is required for the register_iwad action.');
        }

        try {
            $name = $this->iwads->registerUserIwad($path);
        } catch (DoomGeneratorException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success(json_encode([
            'registered' => true,
            'name' => $name,
            'note' => 'IWAD registered and available for use in projects.',
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function listIwads(): ToolResult
    {
        $iwads = $this->iwads->listIwads();

        if ($iwads === []) {
            return ToolResult::success(json_encode([
                'iwads' => [],
                'note' => 'No IWADs available. Use doom_toolchain action "install_freedoom" to download free alternatives, or "register_iwad" to register your own.',
            ], JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        return ToolResult::success(json_encode([
            'iwads' => $iwads,
            'total' => count($iwads),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }
}
