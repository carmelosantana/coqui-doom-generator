<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Wad;

/**
 * WAD file type — IWAD (Internal) contains base game data,
 * PWAD (Patch) contains modifications that override IWAD lumps.
 */
enum WadType: string
{
    case IWAD = 'IWAD';
    case PWAD = 'PWAD';

    /**
     * Attempt to parse a 4-byte magic string into a WadType.
     */
    public static function fromMagic(string $magic): ?self
    {
        return self::tryFrom($magic);
    }
}
