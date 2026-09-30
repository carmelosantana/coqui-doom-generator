<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitDoomGenerator\Exception\DoomGeneratorException;
use CarmeloSantana\CoquiToolkitDoomGenerator\Project\ProjectManager;

/**
 * Create and manage mod projects with standardized directory structure.
 */
final readonly class DoomProjectTool
{
    public function __construct(
        private ProjectManager $projects,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'doom_project',
            description: 'Create and manage Doom mod projects. Projects have a standardized directory structure for organizing assets (graphics, sprites, flats, sounds, music, scripts) and building PWADs.',
            parameters: [
                new EnumParameter('action', 'Action to perform.', ['init', 'list', 'info'], required: true),
                new StringParameter('name', 'Project name (required for init, info).', required: false),
                new StringParameter('iwad', 'Target IWAD name (default: freedoom2.wad). Used with init.', required: false),
                new EnumParameter('source_port', 'Target source port. Used with init.', ['gzdoom', 'chocolate-doom', 'dsda-doom'], required: false),
                new StringParameter('description', 'Project description. Used with init.', required: false),
                new StringParameter('project_id', 'Coqui project ID to link this mod project to a Coqui project for sprint/artifact tracking. Used with init.', required: false),
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
            'init' => $this->initProject($input),
            'list' => $this->listProjects(),
            'info' => $this->projectInfo($input),
            default => ToolResult::error(sprintf('Unknown doom_project action: "%s". Use: init, list, info.', $action)),
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function initProject(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for the init action.');
        }

        $options = [];
        if (isset($input['iwad'])) {
            $options['iwad'] = (string) $input['iwad'];
        }
        if (isset($input['source_port'])) {
            $options['source_port'] = (string) $input['source_port'];
        }
        if (isset($input['description'])) {
            $options['description'] = (string) $input['description'];
        }
        if (isset($input['project_id'])) {
            $options['coqui_project_id'] = (string) $input['project_id'];
        }

        try {
            $result = $this->projects->init($name, $options);
        } catch (DoomGeneratorException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success(json_encode([
            'created' => true,
            'name' => $result['name'],
            'path' => $result['path'],
            'iwad' => $result['manifest']['iwad'],
            'source_port' => $result['manifest']['source_port'],
            'coqui_project_id' => $result['manifest']['coqui_project_id'] ?? null,
            'directories' => ['graphics', 'sprites', 'flats', 'sounds', 'music', 'patches', 'scripts', 'lumps', 'build'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }

    private function listProjects(): ToolResult
    {
        $projects = $this->projects->listProjects();

        if ($projects === []) {
            return ToolResult::success('No projects found. Use doom_project action "init" to create one.');
        }

        $list = [];
        foreach ($projects as $project) {
            $list[] = [
                'name' => $project['name'],
                'iwad' => $project['manifest']['iwad'] ?? 'unknown',
                'source_port' => $project['manifest']['source_port'] ?? 'unknown',
                'last_build' => $project['manifest']['last_build'] ?? 'never',
                'build_count' => $project['manifest']['build_count'] ?? 0,
            ];
        }

        return ToolResult::success(json_encode($list, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '[]');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function projectInfo(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for the info action.');
        }

        try {
            $info = $this->projects->info($name);
        } catch (DoomGeneratorException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success(json_encode($info, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }
}
