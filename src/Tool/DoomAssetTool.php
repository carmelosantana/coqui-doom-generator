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
 * Add, remove, list, or extract assets in a mod project.
 */
final readonly class DoomAssetTool
{
    public function __construct(
        private ProjectManager $projects,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'doom_asset',
            description: 'Manage assets (graphics, sprites, flats, sounds, music, patches) in a Doom mod project. Add files from the workspace, remove existing assets, list project contents, or extract lumps.',
            parameters: [
                new EnumParameter('action', 'Action to perform.', ['add', 'remove', 'list', 'extract'], required: true),
                new StringParameter('project', 'Project name.', required: true),
                new StringParameter('lump_name', 'Lump name for add/remove. Max 8 chars, auto-uppercased. For add: overrides the filename.', required: false),
                new StringParameter('file_path', 'Source file path (relative to workspace) for add. Output path for extract.', required: false),
                new EnumParameter('category', 'Asset category directory.', ['graphic', 'sprite', 'flat', 'sound', 'music', 'patch', 'script', 'lump'], required: false),
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
            'add' => $this->addAsset($input),
            'remove' => $this->removeAsset($input),
            'list' => $this->listAssets($input),
            'extract' => $this->extractAsset($input),
            default => ToolResult::error(sprintf('Unknown doom_asset action: "%s". Use: add, remove, list, extract.', $action)),
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function addAsset(array $input): ToolResult
    {
        $project = trim((string) ($input['project'] ?? ''));
        $filePath = trim((string) ($input['file_path'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'graphic'));
        $lumpName = trim((string) ($input['lump_name'] ?? ''));

        if ($project === '' || $filePath === '') {
            return ToolResult::error('Both "project" and "file_path" are required for the add action.');
        }

        try {
            $projectPath = $this->projects->resolveProjectPath($project);
        } catch (DoomGeneratorException $e) {
            return ToolResult::error($e->getMessage());
        }

        $categoryDir = $this->categoryToDir($category);
        $targetDir = $projectPath . '/' . $categoryDir;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Determine filename
        if ($lumpName !== '') {
            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            $filename = strtoupper(substr($lumpName, 0, 8)) . ($ext !== '' ? '.' . $ext : '');
        } else {
            $filename = basename($filePath);
        }

        $targetPath = $targetDir . '/' . $filename;

        // The source file should be in the workspace
        if (!is_file($filePath) && !str_starts_with($filePath, '/')) {
            // Try resolving relative to some common paths
            $candidates = [$projectPath . '/' . $filePath];
            $found = false;
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    $filePath = $candidate;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ToolResult::error(sprintf('Source file not found: %s', $filePath));
            }
        }

        if (!is_file($filePath)) {
            return ToolResult::error(sprintf('Source file not found: %s', $filePath));
        }

        if (!copy($filePath, $targetPath)) {
            return ToolResult::error(sprintf('Failed to copy file to %s', $targetPath));
        }

        return ToolResult::success(json_encode([
            'added' => true,
            'project' => $project,
            'category' => $category,
            'filename' => $filename,
            'path' => $targetPath,
            'size' => filesize($targetPath) ?: 0,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function removeAsset(array $input): ToolResult
    {
        $project = trim((string) ($input['project'] ?? ''));
        $lumpName = trim((string) ($input['lump_name'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));

        if ($project === '' || $lumpName === '') {
            return ToolResult::error('Both "project" and "lump_name" are required for the remove action.');
        }

        try {
            $projectPath = $this->projects->resolveProjectPath($project);
        } catch (DoomGeneratorException $e) {
            return ToolResult::error($e->getMessage());
        }

        $removed = [];

        // Search in specific category or all categories
        $dirs = $category !== '' ? [$this->categoryToDir($category)] : ['graphics', 'sprites', 'flats', 'sounds', 'music', 'patches', 'scripts', 'lumps'];

        foreach ($dirs as $dir) {
            $catPath = $projectPath . '/' . $dir;
            if (!is_dir($catPath)) {
                continue;
            }

            $files = glob($catPath . '/*') ?: [];
            foreach ($files as $file) {
                $name = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                if ($name === strtoupper($lumpName)) {
                    unlink($file);
                    $removed[] = $dir . '/' . basename($file);
                }
            }
        }

        if ($removed === []) {
            return ToolResult::error(sprintf('Asset "%s" not found in project "%s".', $lumpName, $project));
        }

        return ToolResult::success(json_encode([
            'removed' => true,
            'project' => $project,
            'files' => $removed,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function listAssets(array $input): ToolResult
    {
        $project = trim((string) ($input['project'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));

        if ($project === '') {
            return ToolResult::error('The "project" parameter is required for the list action.');
        }

        try {
            $projectPath = $this->projects->resolveProjectPath($project);
        } catch (DoomGeneratorException $e) {
            return ToolResult::error($e->getMessage());
        }

        $dirs = $category !== '' ? [$this->categoryToDir($category)] : ['graphics', 'sprites', 'flats', 'sounds', 'music', 'patches', 'scripts', 'lumps'];
        $assets = [];

        foreach ($dirs as $dir) {
            $catPath = $projectPath . '/' . $dir;
            if (!is_dir($catPath)) {
                continue;
            }

            $files = glob($catPath . '/*') ?: [];
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }

                $assets[] = [
                    'category' => $dir,
                    'filename' => basename($file),
                    'lump_name' => strtoupper(substr(pathinfo($file, PATHINFO_FILENAME), 0, 8)),
                    'size' => filesize($file) ?: 0,
                ];
            }
        }

        return ToolResult::success(json_encode([
            'project' => $project,
            'assets' => $assets,
            'total' => count($assets),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function extractAsset(array $input): ToolResult
    {
        $project = trim((string) ($input['project'] ?? ''));
        $lumpName = trim((string) ($input['lump_name'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));

        if ($project === '' || $lumpName === '') {
            return ToolResult::error('Both "project" and "lump_name" are required for the extract action.');
        }

        try {
            $projectPath = $this->projects->resolveProjectPath($project);
        } catch (DoomGeneratorException $e) {
            return ToolResult::error($e->getMessage());
        }

        $dirs = $category !== '' ? [$this->categoryToDir($category)] : ['graphics', 'sprites', 'flats', 'sounds', 'music', 'patches', 'scripts', 'lumps'];

        foreach ($dirs as $dir) {
            $catPath = $projectPath . '/' . $dir;
            if (!is_dir($catPath)) {
                continue;
            }

            $files = glob($catPath . '/*') ?: [];
            foreach ($files as $file) {
                $name = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                if ($name === strtoupper($lumpName)) {
                    $content = file_get_contents($file);
                    if ($content === false) {
                        return ToolResult::error(sprintf('Failed to read asset: %s', $file));
                    }

                    $isBinary = !mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0");

                    return ToolResult::success(json_encode([
                        'found' => true,
                        'path' => $file,
                        'category' => $dir,
                        'size' => strlen($content),
                        'is_binary' => $isBinary,
                        'content' => $isBinary ? '(binary data, ' . strlen($content) . ' bytes)' : $content,
                    ], JSON_UNESCAPED_SLASHES) ?: '{}');
                }
            }
        }

        return ToolResult::error(sprintf('Asset "%s" not found in project "%s".', $lumpName, $project));
    }

    private function categoryToDir(string $category): string
    {
        return match ($category) {
            'graphic' => 'graphics',
            'sprite' => 'sprites',
            'flat' => 'flats',
            'sound' => 'sounds',
            'music' => 'music',
            'patch' => 'patches',
            'script' => 'scripts',
            'lump' => 'lumps',
            default => 'lumps',
        };
    }
}
