<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Wad;

use CarmeloSantana\CoquiToolkitDoomGenerator\Exception\DoomGeneratorException;

/**
 * Creates PWAD files from a collection of named lumps.
 *
 * Writes the binary data in correct WAD format:
 *   1. Header (12 bytes) — with PWAD magic, lump count, directory offset
 *   2. Lump data — concatenated raw binary blobs
 *   3. Directory — 16-byte entries mapping names to offsets/sizes
 */
final class WadWriter
{
    /** @var array<int, array{name: string, data: string}> */
    private array $lumps = [];

    /**
     * Add a lump with raw binary data.
     *
     * @param string $name Lump name (max 8 chars, uppercased, null-padded)
     * @param string $data Raw binary content
     */
    public function addLump(string $name, string $data): self
    {
        // WAD format: lump names are max 8 chars, null-padded
        $name = substr($name, 0, 8);

        $this->lumps[] = [
            'name' => strtoupper($name),
            'data' => $data,
        ];

        return $this;
    }

    /**
     * Add a marker lump (zero-size namespace delimiter).
     */
    public function addMarker(string $name): self
    {
        return $this->addLump($name, '');
    }

    /**
     * Add a lump from a file on disk.
     *
     * @throws DoomGeneratorException If the file cannot be read.
     */
    public function addLumpFromFile(string $name, string $filePath): self
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw DoomGeneratorException::wadNotFound($filePath);
        }

        $data = file_get_contents($filePath);
        if ($data === false) {
            throw DoomGeneratorException::wadNotFound($filePath);
        }

        return $this->addLump($name, $data);
    }

    /**
     * Write the PWAD to a file.
     *
     * @throws DoomGeneratorException If the file cannot be written.
     */
    public function writeTo(string $outputPath): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw DoomGeneratorException::buildFailed(sprintf('Cannot create directory: %s', $dir));
        }

        $lumpCount = count($this->lumps);

        // Calculate offsets: header is 12 bytes, then lump data
        $dataOffset = 12;
        $entries = [];

        foreach ($this->lumps as $lump) {
            $size = strlen($lump['data']);
            $entries[] = new WadLump(
                name: $lump['name'],
                offset: $dataOffset,
                size: $size,
            );
            $dataOffset += $size;
        }

        // Directory starts right after all lump data
        $directoryOffset = $dataOffset;

        $header = new WadHeader(
            type: WadType::PWAD,
            lumpCount: $lumpCount,
            directoryOffset: $directoryOffset,
        );

        // Build the complete binary output
        $output = $header->toBinary();

        foreach ($this->lumps as $lump) {
            $output .= $lump['data'];
        }

        foreach ($entries as $entry) {
            $output .= $entry->toDirectoryEntry();
        }

        $result = file_put_contents($outputPath, $output);
        if ($result === false) {
            throw DoomGeneratorException::buildFailed(sprintf('Failed to write WAD file: %s', $outputPath));
        }
    }

    /**
     * Get the number of lumps added.
     */
    public function lumpCount(): int
    {
        return count($this->lumps);
    }

    /**
     * Reset the writer for reuse.
     */
    public function reset(): self
    {
        $this->lumps = [];

        return $this;
    }
}
