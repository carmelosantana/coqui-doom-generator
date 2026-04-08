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
use CarmeloSantana\CoquiToolkitDoomGenerator\Project\ProjectManager;
use CarmeloSantana\CoquiToolkitDoomGenerator\Runtime\DoomRunner;

/**
 * Launch a mod in a source port for testing and playtesting.
 */
final readonly class DoomRunTool
{
    public function __construct(
        private DoomRunner $runner,
        private ProjectManager $projects,
        private IwadManager $iwads,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'doom_run',
            description: 'Launch a built Doom mod in a source port for playtesting. Can launch by project name (auto-finds latest build WAD) or by explicit wad_path. Requires a source port (GZDoom, Chocolate Doom, or DSDA-Doom) installed.',
            parameters: [
                new StringParameter('project', 'Project name (uses latest build from project build/ dir).', required: false),
                new StringParameter('wad_path', 'Explicit path to a PWAD to load (relative to workspace).', required: false),
                new EnumParameter('source_port', 'Source port to use.', ['gzdoom', 'chocolate-doom', 'dsda-doom'], required: false),
                new StringParameter('iwad', 'IWAD name to use (default: from project manifest or freedoom2.wad).', required: false),
                new StringParameter('extra_args', 'Additional command-line arguments for the source port.', required: false),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $project = trim((string) ($input['project'] ?? ''));
        $wadPath = trim((string) ($input['wad_path'] ?? ''));
        $sourcePort = trim((string) ($input['source_port'] ?? 'gzdoom'));
        $iwadName = trim((string) ($input['iwad'] ?? ''));
        $extraArgs = trim((string) ($input['extra_args'] ?? ''));

        // Resolve PWAD to load
        if ($project !== '' && $wadPath === '') {
            $wadPath = $this->findLatestBuild($project);
            if ($wadPath === null) {
                return ToolResult::error(sprintf(
                    'No build found for project "%s". Run doom_build first.',
                    $project,
                ));
            }
        }

        if ($wadPath === '') {
            return ToolResult::error('Either "project" or "wad_path" is required.');
        }

        // Resolve IWAD
        if ($iwadName === '' && $project !== '') {
            $iwadName = $this->getProjectIwad($project);
        }
        if ($iwadName === '') {
            $iwadName = 'freedoom2.wad';
        }

        try {
            $iwadPath = $this->iwads->resolveIwad($iwadName);
        } catch (DoomGeneratorException $e) {
            return ToolResult::error(sprintf(
                'IWAD "%s" not found. Use doom_toolchain action "install_freedoom" to download Freedoom, or doom_toolchain action "register_iwad" to register your own IWAD.',
                $iwadName,
            ));
        }

        // Build source port arguments
        $args = ['-iwad', $iwadPath, '-file', $wadPath];

        if ($extraArgs !== '') {
            $parts = explode(' ', $extraArgs);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $args[] = $part;
                }
            }
        }

        // Check if source port is available
        $toolchain = $this->runner->checkToolchain();
        $portKey = str_replace('-', '_', $sourcePort);
        if (!($toolchain[$portKey]['available'] ?? false) && !($toolchain[$sourcePort]['available'] ?? false)) {
            return ToolResult::error(sprintf(
                'Source port "%s" is not installed. Use doom_toolchain action "status" to see available options.',
                $sourcePort,
            ));
        }

        // Launch (non-blocking, just starts the process)
        $result = $this->runner->launchSourcePort($sourcePort, $args);

        if ($result->succeeded()) {
            return ToolResult::success(json_encode([
                'launched' => true,
                'source_port' => $sourcePort,
                'iwad' => $iwadPath,
                'pwad' => $wadPath,
                'note' => 'Source port launched. It will open in a separate window.',
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }

        return $result->toToolResult();
    }

    private function findLatestBuild(string $project): ?string
    {
        try {
            $projectPath = $this->projects->resolveProjectPath($project);
        } catch (DoomGeneratorException) {
            return null;
        }

        $buildDir = $projectPath . '/build';
        if (!is_dir($buildDir)) {
            return null;
        }

        $wads = glob($buildDir . '/*.wad') ?: [];
        if ($wads === []) {
            return null;
        }

        // Sort by modification time, newest first
        usort($wads, fn(string $a, string $b): int => (filemtime($b) ?: 0) - (filemtime($a) ?: 0));

        return $wads[0];
    }

    private function getProjectIwad(string $project): string
    {
        try {
            $projectPath = $this->projects->resolveProjectPath($project);
        } catch (DoomGeneratorException) {
            return '';
        }

        $manifestPath = $projectPath . '/project.json';
        if (!is_file($manifestPath)) {
            return '';
        }

        $data = file_get_contents($manifestPath);
        if ($data === false) {
            return '';
        }

        $manifest = json_decode($data, true);
        return (string) ($manifest['iwad'] ?? '');
    }
}
