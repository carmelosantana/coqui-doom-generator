<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitDoomGenerator\Exception\DoomGeneratorException;
use CarmeloSantana\CoquiToolkitDoomGenerator\Project\ProjectManager;
use CarmeloSantana\CoquiToolkitDoomGenerator\Runtime\DoomRunner;

/**
 * Build a project's assets into a playable PWAD using DeuTex.
 */
final readonly class DoomBuildTool
{
    public function __construct(
        private DoomRunner $runner,
        private ProjectManager $projects,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'doom_build',
            description: 'Build a Doom mod project into a playable PWAD file using DeuTex. Assembles all project assets (graphics, sprites, flats, sounds, music, patches) into a single WAD. Requires DeuTex installed.',
            parameters: [
                new StringParameter('project', 'Project name to build.', required: true),
                new StringParameter('output_name', 'Output WAD filename (default: project name + .wad).', required: false),
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
        $outputName = trim((string) ($input['output_name'] ?? ''));

        if ($project === '') {
            return ToolResult::error('The "project" parameter is required.');
        }

        try {
            $projectPath = $this->projects->resolveProjectPath($project);
        } catch (DoomGeneratorException $e) {
            return ToolResult::error($e->getMessage());
        }

        // Build output path
        $buildDir = $projectPath . '/build';
        if (!is_dir($buildDir)) {
            mkdir($buildDir, 0755, true);
        }

        if ($outputName === '') {
            $outputName = $project . '.wad';
        }
        if (!str_ends_with(strtolower($outputName), '.wad')) {
            $outputName .= '.wad';
        }

        $outputPath = $buildDir . '/' . $outputName;

        // Ensure DeuTex config exists
        $deutexCfg = $projectPath . '/deutex.cfg';
        if (!is_file($deutexCfg)) {
            $this->projects->generateDeutexConfig($projectPath);
        }

        // Build DeuTex arguments
        $args = ['-doom2', 'bootstrap', '-out', $outputPath, '-dir', $projectPath];

        // Run deutex — returns install instructions on error if binary is missing
        $result = $this->runner->deutex($args, $projectPath);

        if ($result->succeeded()) {
            $this->projects->recordBuild($projectPath);

            $manifest = $this->projects->readManifest($projectPath);
            $wadSize = is_file($outputPath) ? filesize($outputPath) : 0;

            return ToolResult::success(json_encode([
                'built' => true,
                'project' => $project,
                'output' => $outputPath,
                'size' => $wadSize,
                'size_human' => $this->formatSize($wadSize ?: 0),
                'coqui_project_id' => $manifest['coqui_project_id'] ?? null,
                'log' => $result->output(),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        }

        return $result->toToolResult();
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return sprintf('%.1f %s', $size, $units[$index]);
    }
}
