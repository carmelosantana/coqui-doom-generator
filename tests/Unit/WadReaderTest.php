<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitDoomGenerator\Wad\WadHeader;
use CarmeloSantana\CoquiToolkitDoomGenerator\Wad\WadLump;
use CarmeloSantana\CoquiToolkitDoomGenerator\Wad\WadReader;
use CarmeloSantana\CoquiToolkitDoomGenerator\Wad\WadType;
use CarmeloSantana\CoquiToolkitDoomGenerator\Exception\DoomGeneratorException;

// ── Helpers ──────────────────────────────────────────────────────────────

/**
 * Build a minimal valid PWAD binary in memory and write to a temp file.
 *
 * WAD structure:
 *  - 12-byte header: magic (4) + lump count (4) + dir offset (4)
 *  - lump data (concatenated)
 *  - directory entries: 16 bytes each (offset 4 + size 4 + name 8)
 *
 * @param array<array{name: string, data: string}> $lumps
 */
function createTestWad(string $path, WadType $type, array $lumps): void
{
    $magic = $type->value;
    $lumpCount = count($lumps);

    // Calculate offsets
    $headerSize = 12;
    $dataOffset = $headerSize;
    $totalDataSize = 0;

    $entries = [];
    foreach ($lumps as $lump) {
        $offset = $dataOffset + $totalDataSize;
        $size = strlen($lump['data']);
        $entries[] = [
            'name' => $lump['name'],
            'data' => $lump['data'],
            'offset' => $offset,
            'size' => $size,
        ];
        $totalDataSize += $size;
    }

    $directoryOffset = $headerSize + $totalDataSize;

    // Build binary
    $header = pack('a4VV', $magic, $lumpCount, $directoryOffset);

    $data = '';
    foreach ($entries as $entry) {
        $data .= $entry['data'];
    }

    $directory = '';
    foreach ($entries as $entry) {
        $directory .= pack('VVa8', $entry['offset'], $entry['size'], $entry['name']);
    }

    file_put_contents($path, $header . $data . $directory);
}

function tempWadPath(): string
{
    return sys_get_temp_dir() . '/coqui-doom-test-' . bin2hex(random_bytes(4)) . '.wad';
}

// ── Header Parsing ───────────────────────────────────────────────────────

test('parses a minimal PWAD with no lumps', function () {
    $path = tempWadPath();
    createTestWad($path, WadType::PWAD, []);

    try {
        $reader = new WadReader($path);
        $reader->parse();
        $header = $reader->header();

        expect($header)->toBeInstanceOf(WadHeader::class);
        expect($header->type)->toBe(WadType::PWAD);
        expect($header->lumpCount)->toBe(0);
        expect($reader->lumps())->toHaveCount(0);
    } finally {
        @unlink($path);
    }
});

test('parses an IWAD header correctly', function () {
    $path = tempWadPath();
    createTestWad($path, WadType::IWAD, [
        ['name' => 'PLAYPAL', 'data' => str_repeat("\x00", 256)],
    ]);

    try {
        $reader = new WadReader($path);
        $reader->parse();
        $header = $reader->header();

        expect($header->type)->toBe(WadType::IWAD);
        expect($header->lumpCount)->toBe(1);
    } finally {
        @unlink($path);
    }
});

// ── Lump Listing ─────────────────────────────────────────────────────────

test('lists all lumps with correct names and sizes', function () {
    $path = tempWadPath();
    createTestWad($path, WadType::PWAD, [
        ['name' => 'DSPISTOL', 'data' => str_repeat('A', 100)],
        ['name' => 'TEXTURES', 'data' => str_repeat('B', 50)],
        ['name' => 'MAP01', 'data' => ''],
    ]);

    try {
        $reader = new WadReader($path);
        $reader->parse();
        $lumps = $reader->lumps();

        expect($lumps)->toHaveCount(3);
        expect($lumps[0]->name)->toBe('DSPISTOL');
        expect($lumps[0]->size)->toBe(100);
        expect($lumps[1]->name)->toBe('TEXTURES');
        expect($lumps[1]->size)->toBe(50);
        expect($lumps[2]->name)->toBe('MAP01');
        expect($lumps[2]->size)->toBe(0);
        expect($lumps[2]->isMarker())->toBeTrue();
    } finally {
        @unlink($path);
    }
});

// ── Lump Search ──────────────────────────────────────────────────────────

test('findLumps filters by glob pattern', function () {
    $path = tempWadPath();
    createTestWad($path, WadType::PWAD, [
        ['name' => 'MAP01', 'data' => ''],
        ['name' => 'MAP02', 'data' => ''],
        ['name' => 'DSPISTOL', 'data' => 'x'],
        ['name' => 'MAP10', 'data' => ''],
    ]);

    try {
        $reader = new WadReader($path);
        $reader->parse();
        $maps = $reader->findLumps('MAP*');

        expect($maps)->toHaveCount(3);
        foreach ($maps as $lump) {
            expect($lump->name)->toStartWith('MAP');
        }
    } finally {
        @unlink($path);
    }
});

test('getLump returns specific lump by name', function () {
    $path = tempWadPath();
    createTestWad($path, WadType::PWAD, [
        ['name' => 'ALPHA', 'data' => 'aaaa'],
        ['name' => 'BETA', 'data' => 'bbbb'],
    ]);

    try {
        $reader = new WadReader($path);
        $reader->parse();
        $beta = $reader->getLump('BETA');

        expect($beta)->not->toBeNull();
        expect($beta->name)->toBe('BETA');
        expect($beta->size)->toBe(4);
    } finally {
        @unlink($path);
    }
});

test('getLump returns null for non-existent lump', function () {
    $path = tempWadPath();
    createTestWad($path, WadType::PWAD, [
        ['name' => 'EXISTS', 'data' => 'x'],
    ]);

    try {
        $reader = new WadReader($path);
        $reader->parse();

        expect($reader->getLump('MISSING'))->toBeNull();
    } finally {
        @unlink($path);
    }
});

// ── Lump Data Extraction ─────────────────────────────────────────────────

test('extractLumpData returns raw binary data', function () {
    $path = tempWadPath();
    $payload = random_bytes(64);
    createTestWad($path, WadType::PWAD, [
        ['name' => 'TESTDATA', 'data' => $payload],
    ]);

    try {
        $reader = new WadReader($path);
        $reader->parse();
        $lump = $reader->getLump('TESTDATA');
        $data = $reader->extractLumpData($lump);

        expect($data)->toBe($payload);
    } finally {
        @unlink($path);
    }
});

test('extractByName returns data for named lump', function () {
    $path = tempWadPath();
    createTestWad($path, WadType::PWAD, [
        ['name' => 'MYDATA', 'data' => 'hello-wad'],
    ]);

    try {
        $reader = new WadReader($path);
        $reader->parse();
        $data = $reader->extractByName('MYDATA');

        expect($data)->toBe('hello-wad');
    } finally {
        @unlink($path);
    }
});

test('extractByName returns null for non-existent lump', function () {
    $path = tempWadPath();
    createTestWad($path, WadType::PWAD, [
        ['name' => 'REAL', 'data' => 'x'],
    ]);

    try {
        $reader = new WadReader($path);
        $reader->parse();

        expect($reader->extractByName('FAKE'))->toBeNull();
    } finally {
        @unlink($path);
    }
});

// ── Summary ──────────────────────────────────────────────────────────────

test('summary returns formatted header info', function () {
    $path = tempWadPath();
    createTestWad($path, WadType::PWAD, [
        ['name' => 'A', 'data' => 'x'],
        ['name' => 'B', 'data' => 'yy'],
    ]);

    try {
        $reader = new WadReader($path);
        $reader->parse();
        $summary = $reader->summary();

        expect($summary)
            ->toBeArray()
            ->toHaveKeys(['type', 'lump_count', 'directory_offset', 'file_size']);
        expect($summary['type'])->toBe('PWAD');
        expect($summary['lump_count'])->toBe(2);
    } finally {
        @unlink($path);
    }
});

// ── Error Handling ───────────────────────────────────────────────────────

test('throws on non-existent file', function () {
    $reader = new WadReader('/tmp/does-not-exist-' . bin2hex(random_bytes(4)) . '.wad');
    $reader->parse();
})->throws(DoomGeneratorException::class);

test('throws on invalid magic bytes', function () {
    $path = tempWadPath();
    // Write garbage header
    file_put_contents($path, 'XXXX' . pack('VV', 0, 12));

    try {
        $reader = new WadReader($path);
        $reader->parse();
    } catch (DoomGeneratorException $e) {
        expect($e->getMessage())->toContain('magic');
        return;
    } finally {
        @unlink($path);
    }

    $this->fail('Expected DoomGeneratorException for invalid magic bytes');
});

test('throws on truncated file', function () {
    $path = tempWadPath();
    // Write only 8 bytes — too short for a 12-byte header
    file_put_contents($path, 'PWAD1234');

    try {
        $reader = new WadReader($path);
        $reader->parse();
    } catch (DoomGeneratorException $e) {
        expect($e->getMessage())->toContain('Invalid');
        return;
    } finally {
        @unlink($path);
    }

    $this->fail('Expected DoomGeneratorException for truncated file');
});

// ── Value Objects ────────────────────────────────────────────────────────

test('WadType fromMagic resolves correctly', function () {
    expect(WadType::fromMagic('IWAD'))->toBe(WadType::IWAD);
    expect(WadType::fromMagic('PWAD'))->toBe(WadType::PWAD);
    expect(WadType::fromMagic('XXXX'))->toBeNull();
});

test('WadHeader toBinary produces correct 12-byte header', function () {
    $header = new WadHeader(WadType::PWAD, 5, 1024);
    $binary = $header->toBinary();

    expect(strlen($binary))->toBe(12);
    expect(substr($binary, 0, 4))->toBe('PWAD');

    $unpacked = unpack('Vlumps/Voffset', substr($binary, 4));
    expect($unpacked['lumps'])->toBe(5);
    expect($unpacked['offset'])->toBe(1024);
});

test('WadLump toDirectoryEntry produces correct 16-byte entry', function () {
    $lump = new WadLump('TESTLUMP', 256, 1024);
    $entry = $lump->toDirectoryEntry();

    expect(strlen($entry))->toBe(16);

    $unpacked = unpack('Voffset/Vsize/a8name', $entry);
    expect($unpacked['offset'])->toBe(256);
    expect($unpacked['size'])->toBe(1024);
    expect(rtrim($unpacked['name'], "\0"))->toBe('TESTLUMP');
});

test('WadLump isMarker detects zero-size lumps', function () {
    $marker = new WadLump('S_START', 100, 0);
    $data = new WadLump('SPRITE1', 100, 512);

    expect($marker->isMarker())->toBeTrue();
    expect($data->isMarker())->toBeFalse();
});

test('WadLump matchesPattern uses fnmatch glob', function () {
    $lump = new WadLump('MAP01', 0, 0);

    expect($lump->matchesPattern('MAP*'))->toBeTrue();
    expect($lump->matchesPattern('MAP??'))->toBeTrue();
    expect($lump->matchesPattern('D_*'))->toBeFalse();
});
