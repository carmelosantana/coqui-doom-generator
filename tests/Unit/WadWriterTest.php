<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitDoomGenerator\Wad\WadReader;
use CarmeloSantana\CoquiToolkitDoomGenerator\Wad\WadType;
use CarmeloSantana\CoquiToolkitDoomGenerator\Wad\WadWriter;

// ── Helpers ──────────────────────────────────────────────────────────────

function tempWriterPath(): string
{
    return sys_get_temp_dir() . '/coqui-doom-writer-' . bin2hex(random_bytes(4)) . '.wad';
}

// ── Basic Writing ────────────────────────────────────────────────────────

test('writes a valid PWAD with no lumps', function () {
    $path = tempWriterPath();

    try {
        $writer = new WadWriter();
        $writer->writeTo($path);

        expect(file_exists($path))->toBeTrue();
        expect(filesize($path))->toBe(12); // header only

        $reader = new WadReader($path);
        $reader->parse();

        expect($reader->header()->type)->toBe(WadType::PWAD);
        expect($reader->header()->lumpCount)->toBe(0);
        expect($reader->lumps())->toHaveCount(0);
    } finally {
        @unlink($path);
    }
});

test('writes a PWAD with lumps', function () {
    $path = tempWriterPath();

    try {
        $writer = new WadWriter();
        $writer->addLump('TESTLUMP', 'hello world');
        $writer->addLump('BINDATA', random_bytes(32));

        expect($writer->lumpCount())->toBe(2);

        $writer->writeTo($path);

        $reader = new WadReader($path);
        $reader->parse();

        expect($reader->header()->lumpCount)->toBe(2);
        expect($reader->lumps())->toHaveCount(2);
        expect($reader->lumps()[0]->name)->toBe('TESTLUMP');
        expect($reader->lumps()[1]->name)->toBe('BINDATA');
    } finally {
        @unlink($path);
    }
});

// ── Roundtrip ────────────────────────────────────────────────────────────

test('roundtrip: written data can be read back correctly', function () {
    $path = tempWriterPath();
    $payload1 = 'The quick brown fox';
    $payload2 = random_bytes(128);

    try {
        $writer = new WadWriter();
        $writer->addLump('TEXT1', $payload1);
        $writer->addLump('BINARY1', $payload2);
        $writer->writeTo($path);

        $reader = new WadReader($path);
        $reader->parse();

        expect($reader->extractByName('TEXT1'))->toBe($payload1);
        expect($reader->extractByName('BINARY1'))->toBe($payload2);
    } finally {
        @unlink($path);
    }
});

// ── Markers ──────────────────────────────────────────────────────────────

test('writes markers as zero-size lumps', function () {
    $path = tempWriterPath();

    try {
        $writer = new WadWriter();
        $writer->addMarker('S_START');
        $writer->addLump('SPRITE1', 'sprite-data');
        $writer->addMarker('S_END');
        $writer->writeTo($path);

        $reader = new WadReader($path);
        $reader->parse();
        $lumps = $reader->lumps();

        expect($lumps)->toHaveCount(3);
        expect($lumps[0]->name)->toBe('S_START');
        expect($lumps[0]->isMarker())->toBeTrue();
        expect($lumps[1]->name)->toBe('SPRITE1');
        expect($lumps[1]->isMarker())->toBeFalse();
        expect($lumps[2]->name)->toBe('S_END');
        expect($lumps[2]->isMarker())->toBeTrue();
    } finally {
        @unlink($path);
    }
});

// ── File Lump ────────────────────────────────────────────────────────────

test('addLumpFromFile reads file content into lump', function () {
    $path = tempWriterPath();
    $srcFile = sys_get_temp_dir() . '/coqui-doom-src-' . bin2hex(random_bytes(4)) . '.dat';
    $content = 'file-based lump content';
    file_put_contents($srcFile, $content);

    try {
        $writer = new WadWriter();
        $writer->addLumpFromFile('FROMFILE', $srcFile);
        $writer->writeTo($path);

        $reader = new WadReader($path);
        $reader->parse();

        expect($reader->extractByName('FROMFILE'))->toBe($content);
    } finally {
        @unlink($path);
        @unlink($srcFile);
    }
});

// ── Reset ────────────────────────────────────────────────────────────────

test('reset clears all lumps', function () {
    $writer = new WadWriter();
    $writer->addLump('A', 'data-a');
    $writer->addLump('B', 'data-b');

    expect($writer->lumpCount())->toBe(2);

    $writer->reset();

    expect($writer->lumpCount())->toBe(0);
});

// ── Lump Name Truncation ─────────────────────────────────────────────────

test('lump names are truncated to 8 characters', function () {
    $path = tempWriterPath();

    try {
        $writer = new WadWriter();
        $writer->addLump('LONGERNAME_EXCEEDS', 'data');
        $writer->writeTo($path);

        $reader = new WadReader($path);
        $reader->parse();

        // WAD format stores max 8 chars
        expect(strlen($reader->lumps()[0]->name))->toBeLessThanOrEqual(8);
    } finally {
        @unlink($path);
    }
});

// ── Multiple Writes ──────────────────────────────────────────────────────

test('writer can write multiple WADs sequentially', function () {
    $path1 = tempWriterPath();
    $path2 = tempWriterPath();

    try {
        $writer = new WadWriter();
        $writer->addLump('FIRST', 'aaa');
        $writer->writeTo($path1);

        $writer->reset();
        $writer->addLump('SECOND', 'bbb');
        $writer->addLump('THIRD', 'ccc');
        $writer->writeTo($path2);

        $reader1 = new WadReader($path1);
        $reader1->parse();
        expect($reader1->lumps())->toHaveCount(1);
        expect($reader1->lumps()[0]->name)->toBe('FIRST');

        $reader2 = new WadReader($path2);
        $reader2->parse();
        expect($reader2->lumps())->toHaveCount(2);
        expect($reader2->extractByName('SECOND'))->toBe('bbb');
    } finally {
        @unlink($path1);
        @unlink($path2);
    }
});
