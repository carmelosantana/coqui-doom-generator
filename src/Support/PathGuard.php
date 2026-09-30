<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Support;

use CarmeloSantana\CoquiToolkitDoomGenerator\Exception\DoomGeneratorException;

/**
 * Validates that resolved paths stay within a sandbox boundary.
 */
final class PathGuard
{
    /**
     * Resolve a relative path against a base directory and verify it stays within the sandbox.
     *
     * @throws DoomGeneratorException If the resolved path escapes the base directory.
     */
    public static function resolve(string $relativePath, string $basePath): string
    {
        $absolute = $basePath . '/' . ltrim($relativePath, '/\\');

        $realDir = realpath(dirname($absolute));
        if ($realDir !== false && !str_starts_with($realDir, $basePath)) {
            throw DoomGeneratorException::pathEscapesSandbox($relativePath);
        }

        return $absolute;
    }
}
