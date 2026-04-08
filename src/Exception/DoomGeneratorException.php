<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Exception;

/**
 * Domain exception for the Doom Generator toolkit.
 */
final class DoomGeneratorException extends \RuntimeException
{
    public static function wadNotFound(string $path): self
    {
        return new self(sprintf('WAD file not found or unreadable: %s', $path));
    }

    public static function invalidWadFormat(string $detail): self
    {
        return new self(sprintf('Invalid WAD format: %s', $detail));
    }

    public static function projectExists(string $name): self
    {
        return new self(sprintf('Project "%s" already exists', $name));
    }

    public static function projectNotFound(string $name): self
    {
        return new self(sprintf('Project "%s" not found', $name));
    }

    public static function buildFailed(string $reason): self
    {
        return new self(sprintf('Build failed: %s', $reason));
    }

    public static function binaryNotFound(string $name): self
    {
        return new self(sprintf('Required binary not found: %s. Check doom_toolchain status for install instructions.', $name));
    }

    public static function downloadFailed(string $url, string $reason): self
    {
        return new self(sprintf('Download failed for %s: %s', $url, $reason));
    }

    public static function pathEscapesSandbox(string $path): self
    {
        return new self(sprintf('Path escapes workspace boundary: %s', $path));
    }
}
