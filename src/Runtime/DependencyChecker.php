<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Runtime;

/**
 * Checks for required system dependencies and provides install instructions.
 */
final readonly class DependencyChecker
{
    /**
     * Get installation instructions for a tool by name.
     *
     * @return array{linux: string, macos: string, description: string}
     */
    public static function installInstructions(string $tool): array
    {
        return match ($tool) {
            'deutex' => [
                'linux' => 'sudo apt install deutex',
                'macos' => 'brew install deutex',
                'description' => 'WAD compiler — extracts and injects graphics, sounds, and levels into WAD archives',
            ],
            'gzdoom' => [
                'linux' => 'sudo apt install gzdoom  OR  flatpak install flathub org.zdoom.GZDoom',
                'macos' => 'brew install --cask gzdoom',
                'description' => 'Modern Doom source port with OpenGL rendering, ZScript support, and extensive mod compatibility',
            ],
            'chocolate-doom' => [
                'linux' => 'sudo apt install chocolate-doom',
                'macos' => 'brew install chocolate-doom',
                'description' => 'Vanilla-accurate Doom source port — faithful to the original 1993 experience',
            ],
            'dsda-doom' => [
                'linux' => 'Build from source: https://github.com/kraflab/dsda-doom',
                'macos' => 'Build from source: https://github.com/kraflab/dsda-doom',
                'description' => 'Advanced source port for speedrunning and demo recording with MBF21 support',
            ],
            default => [
                'linux' => 'Not available',
                'macos' => 'Not available',
                'description' => 'Unknown tool',
            ],
        };
    }

    /**
     * Detect the current operating system.
     */
    public static function detectOs(): string
    {
        return match (true) {
            str_contains(PHP_OS_FAMILY, 'Darwin') => 'macos',
            str_contains(PHP_OS_FAMILY, 'Linux') => 'linux',
            str_contains(PHP_OS_FAMILY, 'Windows') => 'windows',
            default => 'unknown',
        };
    }

    /**
     * Build a formatted toolchain status report.
     *
     * @param array<string, array{available: bool, path: string, version: string}> $toolchain
     */
    public static function formatReport(array $toolchain): string
    {
        $os = self::detectOs();
        $lines = ["Doom Toolchain Status (OS: {$os})", str_repeat('=', 45)];

        foreach ($toolchain as $name => $info) {
            $status = $info['available'] ? 'INSTALLED' : 'MISSING';
            $lines[] = sprintf("\n[%s] %s", $status, $name);

            if ($info['available']) {
                $lines[] = sprintf('  Path: %s', $info['path']);
                if ($info['version'] !== '') {
                    $lines[] = sprintf('  Version: %s', $info['version']);
                }
            } else {
                $instructions = self::installInstructions($name);
                $lines[] = sprintf('  Description: %s', $instructions['description']);
                $installCmd = $os === 'macos' ? $instructions['macos'] : $instructions['linux'];
                $lines[] = sprintf('  Install: %s', $installCmd);
            }
        }

        $availableCount = count(array_filter($toolchain, static fn(array $t): bool => $t['available']));
        $lines[] = sprintf("\n%d/%d tools available", $availableCount, count($toolchain));

        if (!($toolchain['deutex']['available'] ?? false)) {
            $lines[] = "\nNote: deutex is required for building PWADs. Install it to enable doom_build.";
        }

        $hasSourcePort = ($toolchain['gzdoom']['available'] ?? false)
            || ($toolchain['chocolate-doom']['available'] ?? false)
            || ($toolchain['dsda-doom']['available'] ?? false);

        if (!$hasSourcePort) {
            $lines[] = "\nNote: No source port found. Install gzdoom or chocolate-doom to launch builds with doom_run.";
        }

        return implode("\n", $lines);
    }
}
