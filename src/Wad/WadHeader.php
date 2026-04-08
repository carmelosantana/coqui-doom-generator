<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Wad;

/**
 * Represents the 12-byte WAD file header.
 *
 * Layout:
 *   - Bytes 0–3: identification ("IWAD" or "PWAD")
 *   - Bytes 4–7: number of lumps (little-endian int32)
 *   - Bytes 8–11: directory offset (little-endian int32)
 */
final readonly class WadHeader
{
    public function __construct(
        public WadType $type,
        public int $lumpCount,
        public int $directoryOffset,
    ) {}

    /**
     * Encode this header into 12 bytes of binary data.
     */
    public function toBinary(): string
    {
        return pack('a4VV', $this->type->value, $this->lumpCount, $this->directoryOffset);
    }
}
