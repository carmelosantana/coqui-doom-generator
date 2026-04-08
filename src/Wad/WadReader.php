<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Wad;

use CarmeloSantana\CoquiToolkitDoomGenerator\Exception\DoomGeneratorException;

/**
 * Native PHP WAD file reader using binary stream parsing.
 *
 * Reads the 12-byte header AND the lump directory from any IWAD or PWAD file.
 * Can extract individual lump data by name or index. Uses pack()/unpack()
 * for all binary I/O — no external dependencies.
 */
final class WadReader
{
    private WadHeader $header;

    /** @var WadLump[] */
    private array $lumps = [];

    private bool $parsed = false;

    public function __construct(
        private readonly string $filePath,
    ) {}

    /**
     * Parse the WAD header and lump directory.
     *
     * @throws DoomGeneratorException If the file cannot be read or has an invalid format.
     */
    public function parse(): self
    {
        if ($this->parsed) {
            return $this;
        }

        if (!is_file($this->filePath) || !is_readable($this->filePath)) {
            throw DoomGeneratorException::wadNotFound($this->filePath);
        }

        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) {
            throw DoomGeneratorException::wadNotFound($this->filePath);
        }

        try {
            $this->parseHeader($handle);
            $this->parseDirectory($handle);
            $this->parsed = true;
        } finally {
            fclose($handle);
        }

        return $this;
    }

    public function header(): WadHeader
    {
        $this->ensureParsed();

        return $this->header;
    }

    /**
     * @return WadLump[]
     */
    public function lumps(): array
    {
        $this->ensureParsed();

        return $this->lumps;
    }

    /**
     * Find lumps matching a glob pattern.
     *
     * @return WadLump[]
     */
    public function findLumps(string $pattern): array
    {
        $this->ensureParsed();

        return array_values(
            array_filter(
                $this->lumps,
                static fn(WadLump $lump): bool => $lump->matchesPattern($pattern),
            ),
        );
    }

    /**
     * Get a lump by exact name (case-insensitive).
     */
    public function getLump(string $name): ?WadLump
    {
        $this->ensureParsed();
        $upper = strtoupper($name);

        foreach ($this->lumps as $lump) {
            if (strtoupper($lump->name) === $upper) {
                return $lump;
            }
        }

        return null;
    }

    /**
     * Extract raw binary data for a lump.
     *
     * @throws DoomGeneratorException If the lump cannot be read.
     */
    public function extractLumpData(WadLump $lump): string
    {
        if ($lump->size <= 0) {
            return '';
        }

        $handle = fopen($this->filePath, 'rb');
        if ($handle === false) {
            throw DoomGeneratorException::wadNotFound($this->filePath);
        }

        try {
            fseek($handle, $lump->offset);
            /** @var int<1, max> $size */
            $size = $lump->size;
            $data = fread($handle, $size);

            if ($data === false || strlen($data) !== $lump->size) {
                throw DoomGeneratorException::invalidWadFormat(
                    sprintf('Failed to read lump "%s" data (%d bytes at offset %d)', $lump->name, $lump->size, $lump->offset),
                );
            }

            return $data;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Extract lump data by name. Returns null when the lump does not exist.
     */
    public function extractByName(string $name): ?string
    {
        $lump = $this->getLump($name);
        if ($lump === null) {
            return null;
        }

        return $this->extractLumpData($lump);
    }

    /**
     * Get a summary of the WAD suitable for agent output.
     *
     * @return array{type: string, lump_count: int, directory_offset: int, file_size: int, file_path: string}
     */
    public function summary(): array
    {
        $this->ensureParsed();

        return [
            'type' => $this->header->type->value,
            'lump_count' => $this->header->lumpCount,
            'directory_offset' => $this->header->directoryOffset,
            'file_size' => filesize($this->filePath) ?: 0,
            'file_path' => $this->filePath,
        ];
    }

    /**
     * @param resource $handle
     */
    private function parseHeader($handle): void
    {
        $headerData = fread($handle, 12);
        if ($headerData === false || strlen($headerData) < 12) {
            throw DoomGeneratorException::invalidWadFormat('File too small to contain a valid WAD header (need 12 bytes)');
        }

        $magic = substr($headerData, 0, 4);
        $type = WadType::fromMagic($magic);

        if ($type === null) {
            throw DoomGeneratorException::invalidWadFormat(
                sprintf('Invalid magic bytes: expected "IWAD" or "PWAD", got "%s"', addcslashes($magic, "\0..\37")),
            );
        }

        $unpacked = unpack('VlumpCount/VdirectoryOffset', $headerData, 4);
        if ($unpacked === false) {
            throw DoomGeneratorException::invalidWadFormat('Failed to unpack header integers');
        }

        $this->header = new WadHeader(
            type: $type,
            lumpCount: $unpacked['lumpCount'],
            directoryOffset: $unpacked['directoryOffset'],
        );
    }

    /**
     * @param resource $handle
     */
    private function parseDirectory($handle): void
    {
        fseek($handle, $this->header->directoryOffset);

        $this->lumps = [];

        for ($i = 0; $i < $this->header->lumpCount; $i++) {
            $entry = fread($handle, 16);
            if ($entry === false || strlen($entry) < 16) {
                throw DoomGeneratorException::invalidWadFormat(
                    sprintf('Truncated directory entry at index %d', $i),
                );
            }

            $unpacked = unpack('Voffset/Vsize', $entry);
            if ($unpacked === false) {
                throw DoomGeneratorException::invalidWadFormat(
                    sprintf('Failed to unpack directory entry at index %d', $i),
                );
            }

            $name = rtrim(substr($entry, 8, 8), "\0");

            $this->lumps[] = new WadLump(
                name: $name,
                offset: $unpacked['offset'],
                size: $unpacked['size'],
            );
        }
    }

    private function ensureParsed(): void
    {
        if (!$this->parsed) {
            $this->parse();
        }
    }
}
