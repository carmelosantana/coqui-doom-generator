<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Wad;

/**
 * Represents a single entry in the WAD lump directory.
 *
 * Each directory entry is 16 bytes:
 *   - Bytes 0–3: file offset to lump data (little-endian int32)
 *   - Bytes 4–7: lump size in bytes (little-endian int32)
 *   - Bytes 8–15: lump name (8 bytes, null-padded ASCII)
 */
final readonly class WadLump
{
    public function __construct(
        public string $name,
        public int $offset,
        public int $size,
    ) {}

    /**
     * Encode this lump directory entry into 16 bytes of binary data.
     */
    public function toDirectoryEntry(): string
    {
        $paddedName = str_pad(strtoupper(substr($this->name, 0, 8)), 8, "\0");

        return pack('VV', $this->offset, $this->size) . $paddedName;
    }

    /**
     * Check if this lump is a marker (zero-size namespace delimiter).
     */
    public function isMarker(): bool
    {
        return $this->size === 0;
    }

    /**
     * Check if the lump name matches a glob pattern.
     */
    public function matchesPattern(string $pattern): bool
    {
        return fnmatch($pattern, $this->name, FNM_CASEFOLD);
    }
}
