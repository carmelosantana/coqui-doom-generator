<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Project;

use CarmeloSantana\CoquiToolkitDoomGenerator\Exception\DoomGeneratorException;

/**
 * Manages mod project lifecycle — create, list, configure, build preparation.
 *
 * Each project lives in {workspace}/doom-generator/projects/{name}/ with:
 *   - project.json manifest (name, IWAD, source port, timestamps)
 *   - graphics/ — wall textures and patches
 *   - sprites/ — actor sprites
 *   - flats/ — floor/ceiling textures
 *   - sounds/ — sound effects
 *   - music/ — music tracks
 *   - patches/ — texture patches
 *   - scripts/ — ZScript, DECORATE, DEHACKED files
 *   - lumps/ — raw lumps (COLORMAP, PLAYPAL, etc.)
 *   - build/ — compiled output PWADs
 */
final class ProjectManager
{
    private readonly string $projectsPath;

    public function __construct(
        private readonly string $workspacePath,
    ) {
        $this->projectsPath = $this->workspacePath . '/projects';
    }

    /**
     * Initialize a new mod project with standard directory structure.
     *
     * @param array{iwad?: string, source_port?: string, description?: string, coqui_project_id?: string} $options
     * @return array{name: string, path: string, manifest: array<string, mixed>}
     * @throws DoomGeneratorException If the project already exists.
     */
    public function init(string $name, array $options = []): array
    {
        $safeName = $this->sanitizeName($name);
        $projectPath = $this->projectsPath . '/' . $safeName;

        if (is_dir($projectPath)) {
            throw DoomGeneratorException::projectExists($safeName);
        }

        // Create directory structure
        $directories = [
            'graphics',
            'sprites',
            'flats',
            'sounds',
            'music',
            'patches',
            'scripts',
            'lumps',
            'build',
        ];

        foreach ($directories as $dir) {
            mkdir($projectPath . '/' . $dir, 0755, true);
        }

        // Create project manifest
        $manifest = [
            'name' => $safeName,
            'description' => $options['description'] ?? '',
            'iwad' => $options['iwad'] ?? 'freedoom2.wad',
            'source_port' => $options['source_port'] ?? 'gzdoom',
            'coqui_project_id' => $options['coqui_project_id'] ?? null,
            'created' => date('c'),
            'last_build' => null,
            'build_count' => 0,
        ];

        $this->writeManifest($projectPath, $manifest);

        // Create a default deutex.cfg skeleton
        $this->writeDefaultDeutexConfig($projectPath, $safeName);

        return [
            'name' => $safeName,
            'path' => $projectPath,
            'manifest' => $manifest,
        ];
    }

    /**
     * List all existing projects.
     *
     * @return array<int, array{name: string, path: string, manifest: array<string, mixed>}>
     */
    public function listProjects(): array
    {
        $this->ensureDirectory();
        $projects = [];

        $entries = glob($this->projectsPath . '/*', GLOB_ONLYDIR) ?: [];
        sort($entries);

        foreach ($entries as $dir) {
            $manifestPath = $dir . '/project.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifest = $this->readManifest($dir);
            if ($manifest !== null) {
                $projects[] = [
                    'name' => basename($dir),
                    'path' => $dir,
                    'manifest' => $manifest,
                ];
            }
        }

        return $projects;
    }

    /**
     * Get detailed info about a specific project.
     *
     * @return array{name: string, path: string, manifest: array<string, mixed>, assets: array<string, int>, builds: string[]}
     * @throws DoomGeneratorException If the project doesn't exist.
     */
    public function info(string $name): array
    {
        $projectPath = $this->resolveProjectPath($name);
        $manifest = $this->readManifest($projectPath);

        if ($manifest === null) {
            throw DoomGeneratorException::projectNotFound($name);
        }

        // Count assets per category
        $categories = ['graphics', 'sprites', 'flats', 'sounds', 'music', 'patches', 'scripts', 'lumps'];
        $assets = [];

        foreach ($categories as $cat) {
            $catPath = $projectPath . '/' . $cat;
            $assets[$cat] = is_dir($catPath) ? count(glob($catPath . '/*') ?: []) : 0;
        }

        // List builds
        $buildPath = $projectPath . '/build';
        $builds = [];
        if (is_dir($buildPath)) {
            $wadFiles = glob($buildPath . '/*.wad') ?: [];
            foreach ($wadFiles as $wad) {
                $builds[] = basename($wad);
            }
        }

        return [
            'name' => basename($projectPath),
            'path' => $projectPath,
            'manifest' => $manifest,
            'assets' => $assets,
            'builds' => $builds,
        ];
    }

    /**
     * Resolve and validate a project path.
     *
     * @throws DoomGeneratorException If the project doesn't exist.
     */
    public function resolveProjectPath(string $name): string
    {
        $safeName = $this->sanitizeName($name);
        $projectPath = $this->projectsPath . '/' . $safeName;

        if (!is_dir($projectPath)) {
            throw DoomGeneratorException::projectNotFound($safeName);
        }

        return $projectPath;
    }

    /**
     * Update the project manifest after a build.
     */
    public function recordBuild(string $projectPath): void
    {
        $manifest = $this->readManifest($projectPath);
        if ($manifest === null) {
            return;
        }

        $manifest['last_build'] = date('c');
        $manifest['build_count'] = ($manifest['build_count'] ?? 0) + 1;

        $this->writeManifest($projectPath, $manifest);
    }

    /**
     * Read the project manifest.
     *
     * @return array<string, mixed>|null
     */
    public function readManifest(string $projectPath): ?array
    {
        $path = $projectPath . '/project.json';
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Generate a DeuTex config file for the project.
     */
    public function generateDeutexConfig(string $projectPath): string
    {
        $manifest = $this->readManifest($projectPath);
        $name = $manifest['name'] ?? basename($projectPath);

        $lines = [
            '; DeuTex configuration for ' . $name,
            '; Auto-generated by Coqui Doom Generator',
            '',
        ];

        // Add graphic entries
        $graphics = glob($projectPath . '/graphics/*') ?: [];
        if ($graphics !== []) {
            $lines[] = '; Wall textures and graphics';
            foreach ($graphics as $file) {
                $lumpName = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                $lines[] = sprintf('GRAPHIC %s 0 0', substr($lumpName, 0, 8));
            }
            $lines[] = '';
        }

        // Add sprite entries
        $sprites = glob($projectPath . '/sprites/*') ?: [];
        if ($sprites !== []) {
            $lines[] = '; Sprites';
            foreach ($sprites as $file) {
                $lumpName = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                $lines[] = sprintf('SPRITE %s 0 0', substr($lumpName, 0, 8));
            }
            $lines[] = '';
        }

        // Add flat entries
        $flats = glob($projectPath . '/flats/*') ?: [];
        if ($flats !== []) {
            $lines[] = '; Floor/ceiling flats';
            foreach ($flats as $file) {
                $lumpName = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                $lines[] = sprintf('FLAT %s', substr($lumpName, 0, 8));
            }
            $lines[] = '';
        }

        // Add sound entries
        $sounds = glob($projectPath . '/sounds/*') ?: [];
        if ($sounds !== []) {
            $lines[] = '; Sound effects';
            foreach ($sounds as $file) {
                $lumpName = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                $lines[] = sprintf('SOUND %s', substr($lumpName, 0, 8));
            }
            $lines[] = '';
        }

        // Add music entries
        $music = glob($projectPath . '/music/*') ?: [];
        if ($music !== []) {
            $lines[] = '; Music';
            foreach ($music as $file) {
                $lumpName = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                $lines[] = sprintf('MUSIC %s', substr($lumpName, 0, 8));
            }
            $lines[] = '';
        }

        // Add patch entries
        $patches = glob($projectPath . '/patches/*') ?: [];
        if ($patches !== []) {
            $lines[] = '; Texture patches';
            foreach ($patches as $file) {
                $lumpName = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                $lines[] = sprintf('PATCH %s 0 0', substr($lumpName, 0, 8));
            }
            $lines[] = '';
        }

        $config = implode("\n", $lines) . "\n";
        $configPath = $projectPath . '/deutex.cfg';
        file_put_contents($configPath, $config);

        return $configPath;
    }

    public function projectsPath(): string
    {
        return $this->projectsPath;
    }

    private function sanitizeName(string $name): string
    {
        // Allow alphanumeric, hyphens, underscores only
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '-', trim($name));

        return $safe !== null && $safe !== '' ? strtolower($safe) : 'untitled';
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(string $projectPath, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($projectPath . '/project.json', $json . "\n");
        }
    }

    private function writeDefaultDeutexConfig(string $projectPath, string $name): void
    {
        $config = <<<CFG
            ; DeuTex configuration for {$name}
            ; Auto-generated by Coqui Doom Generator
            ;
            ; Add assets to the project directories, then run doom_build
            ; to compile them into a PWAD.
            ;
            ; Asset directories:
            ;   graphics/ — wall textures and patches
            ;   sprites/  — actor sprites
            ;   flats/    — floor/ceiling textures
            ;   sounds/   — sound effects
            ;   music/    — music tracks
            ;   patches/  — texture patches
            ;   scripts/  — ZScript, DECORATE, DEHACKED files
            ;   lumps/    — raw lumps
            CFG;

        file_put_contents($projectPath . '/deutex.cfg', $config . "\n");
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->projectsPath)) {
            mkdir($this->projectsPath, 0755, true);
        }
    }
}
