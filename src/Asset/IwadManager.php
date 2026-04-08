<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Asset;

use CarmeloSantana\CoquiToolkitDoomGenerator\Exception\DoomGeneratorException;
use CarmeloSantana\CoquiToolkitDoomGenerator\Wad\WadReader;

/**
 * Manages the IWAD library — Freedoom downloads and user-provided WADs.
 *
 * All IWADs are stored in {workspace}/doom-generator/iwads/.
 * Freedoom is auto-downloaded from GitHub releases (BSD-licensed).
 * Users can register their own IWAD files (doom.wad, doom2.wad, etc.).
 */
final class IwadManager
{
    private const string FREEDOOM_RELEASES_URL = 'https://github.com/freedoom/freedoom/releases/latest/download';
    private const string FREEDOOM1_FILENAME = 'freedoom1.wad';
    private const string FREEDOOM2_FILENAME = 'freedoom2.wad';

    private readonly string $iwadPath;

    public function __construct(
        private readonly string $workspacePath,
    ) {
        $this->iwadPath = $this->workspacePath . '/iwads';
    }

    /**
     * Download Freedoom Phase 1 and Phase 2 WADs from GitHub.
     *
     * @return array{success: bool, message: string, files: string[]}
     */
    public function downloadFreedoom(): array
    {
        $this->ensureDirectory();

        $downloaded = [];
        $errors = [];

        foreach (['freedoom-0.13.0' => self::FREEDOOM1_FILENAME, 'freedoom2-0.13.0' => self::FREEDOOM2_FILENAME] as $archive => $wadName) {
            $targetPath = $this->iwadPath . '/' . $wadName;

            if (is_file($targetPath)) {
                $downloaded[] = $wadName . ' (already exists)';
                continue;
            }

            $zipUrl = self::FREEDOOM_RELEASES_URL . '/' . $archive . '.zip';
            $result = $this->downloadAndExtractWad($zipUrl, $archive, $wadName, $targetPath);

            if ($result === null) {
                $errors[] = sprintf('Failed to download %s', $wadName);
            } else {
                $downloaded[] = $wadName;
            }
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'message' => implode('; ', $errors),
                'files' => $downloaded,
            ];
        }

        return [
            'success' => true,
            'message' => sprintf('Freedoom WADs installed to %s', $this->iwadPath),
            'files' => $downloaded,
        ];
    }

    /**
     * Register a user-provided IWAD by copying it into the managed directory.
     *
     * @throws DoomGeneratorException If the source file is invalid.
     */
    public function registerUserIwad(string $sourcePath): string
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw DoomGeneratorException::wadNotFound($sourcePath);
        }

        // Validate it's actually a WAD
        $reader = new WadReader($sourcePath);
        $reader->parse();

        $this->ensureDirectory();
        $filename = basename($sourcePath);
        $targetPath = $this->iwadPath . '/' . strtolower($filename);

        if (!copy($sourcePath, $targetPath)) {
            throw DoomGeneratorException::buildFailed(sprintf('Failed to copy IWAD to %s', $targetPath));
        }

        return $targetPath;
    }

    /**
     * List all available IWADs with metadata.
     *
     * @return array<int, array{name: string, path: string, type: string, size: int, sha256: string}>
     */
    public function listIwads(): array
    {
        $this->ensureDirectory();
        $iwads = [];

        $files = glob($this->iwadPath . '/*.wad') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $info = [
                'name' => basename($file),
                'path' => $file,
                'type' => 'unknown',
                'size' => filesize($file) ?: 0,
                'sha256' => hash_file('sha256', $file) ?: '',
            ];

            try {
                $reader = new WadReader($file);
                $reader->parse();
                $info['type'] = $reader->header()->type->value;
            } catch (DoomGeneratorException) {
                // Keep type as "unknown" for corrupt files
            }

            $iwads[] = $info;
        }

        return $iwads;
    }

    /**
     * Resolve an IWAD name to its absolute path.
     * Defaults to freedoom2.wad if no name provided.
     *
     * @throws DoomGeneratorException If the IWAD is not found.
     */
    public function resolveIwad(?string $name = null): string
    {
        $name ??= self::FREEDOOM2_FILENAME;

        // Add .wad extension if missing
        if (!str_ends_with(strtolower($name), '.wad')) {
            $name .= '.wad';
        }

        $path = $this->iwadPath . '/' . strtolower($name);

        if (is_file($path)) {
            return $path;
        }

        // Try case-insensitive search
        $files = glob($this->iwadPath . '/*.wad') ?: [];
        foreach ($files as $file) {
            if (strtolower(basename($file)) === strtolower($name)) {
                return $file;
            }
        }

        throw DoomGeneratorException::wadNotFound(
            sprintf('IWAD "%s" not found in %s. Use doom_toolchain action "install_freedoom" to download Freedoom.', $name, $this->iwadPath),
        );
    }

    /**
     * Verify the integrity of a WAD file.
     *
     * @return array{valid: bool, type: string, lump_count: int, size: int, sha256: string}
     */
    public function verifyIntegrity(string $path): array
    {
        $result = [
            'valid' => false,
            'type' => 'unknown',
            'lump_count' => 0,
            'size' => is_file($path) ? (filesize($path) ?: 0) : 0,
            'sha256' => is_file($path) ? (hash_file('sha256', $path) ?: '') : '',
        ];

        try {
            $reader = new WadReader($path);
            $reader->parse();
            $result['valid'] = true;
            $result['type'] = $reader->header()->type->value;
            $result['lump_count'] = $reader->header()->lumpCount;
        } catch (DoomGeneratorException) {
            // Keep defaults
        }

        return $result;
    }

    public function iwadPath(): string
    {
        return $this->iwadPath;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->iwadPath)) {
            mkdir($this->iwadPath, 0755, true);
        }
    }

    /**
     * Download a Freedoom ZIP, extract the WAD file from it.
     */
    private function downloadAndExtractWad(string $zipUrl, string $archiveName, string $wadName, string $targetPath): ?string
    {
        $tmpZip = sys_get_temp_dir() . '/freedoom-' . bin2hex(random_bytes(6)) . '.zip';

        try {
            // Download the ZIP
            $context = stream_context_create([
                'http' => [
                    'timeout' => 120,
                    'follow_location' => true,
                    'user_agent' => 'CoquiBot-DoomGenerator/1.0',
                ],
            ]);

            $zipData = @file_get_contents($zipUrl, false, $context);
            if ($zipData === false) {
                return null;
            }

            if (file_put_contents($tmpZip, $zipData) === false) {
                return null;
            }

            // Extract the WAD from the ZIP
            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                return null;
            }

            // The WAD is typically at {archiveName}/{wadName} inside the ZIP
            $wadInZip = $archiveName . '/' . $wadName;
            $wadData = $zip->getFromName($wadInZip);

            if ($wadData === false) {
                // Try searching for any .wad file
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if ($entryName !== false && str_ends_with(strtolower($entryName), strtolower($wadName))) {
                        $wadData = $zip->getFromName($entryName);
                        break;
                    }
                }
            }

            $zip->close();

            if ($wadData === false) {
                return null;
            }

            if (file_put_contents($targetPath, $wadData) === false) {
                return null;
            }

            return $targetPath;
        } finally {
            @unlink($tmpZip);
        }
    }
}
