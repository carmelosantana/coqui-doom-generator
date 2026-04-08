<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitDoomGenerator\DoomGeneratorToolkit;

// ── Toolkit Tests ────────────────────────────────────────────────────────

test('toolkit implements ToolkitInterface', function () {
    $toolkit = new DoomGeneratorToolkit(sys_get_temp_dir() . '/coqui-doom-test-workspace');

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

test('tools returns all 6 tools', function () {
    $toolkit = new DoomGeneratorToolkit(sys_get_temp_dir() . '/coqui-doom-test-workspace');

    expect($toolkit->tools())->toHaveCount(6);
});

test('each tool implements ToolInterface', function () {
    $toolkit = new DoomGeneratorToolkit(sys_get_temp_dir() . '/coqui-doom-test-workspace');

    foreach ($toolkit->tools() as $tool) {
        expect($tool)->toBeInstanceOf(ToolInterface::class);
    }
});

test('tool names are unique', function () {
    $toolkit = new DoomGeneratorToolkit(sys_get_temp_dir() . '/coqui-doom-test-workspace');
    $names = array_map(fn(ToolInterface $t) => $t->name(), $toolkit->tools());

    expect($names)->toHaveCount(count(array_unique($names)));
});

test('each tool produces a valid function schema', function () {
    $toolkit = new DoomGeneratorToolkit(sys_get_temp_dir() . '/coqui-doom-test-workspace');

    foreach ($toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)
            ->toBeArray()
            ->toHaveKeys(['type', 'function']);

        expect($schema['type'])->toBe('function');
        expect($schema['function'])->toBeArray()->toHaveKeys(['name', 'description', 'parameters']);
        expect($schema['function']['name'])->toBeString()->not->toBeEmpty();
        expect($schema['function']['description'])->toBeString()->not->toBeEmpty();
        expect($schema['function']['parameters'])->toBeArray();
    }
});

test('all tool names start with doom_', function () {
    $toolkit = new DoomGeneratorToolkit(sys_get_temp_dir() . '/coqui-doom-test-workspace');

    foreach ($toolkit->tools() as $tool) {
        expect($tool->name())->toStartWith('doom_');
    }
});

test('guidelines returns non-empty string with XML tag', function () {
    $toolkit = new DoomGeneratorToolkit(sys_get_temp_dir() . '/coqui-doom-test-workspace');
    $guidelines = $toolkit->guidelines();

    expect($guidelines)
        ->toBeString()
        ->not->toBeEmpty()
        ->toContain('<DOOM-GENERATOR-GUIDELINES>')
        ->toContain('</DOOM-GENERATOR-GUIDELINES>');
});

test('fromEnv creates instance', function () {
    $toolkit = DoomGeneratorToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(DoomGeneratorToolkit::class);
    expect($toolkit->tools())->toHaveCount(6);
});

test('expected tool names are present', function () {
    $toolkit = new DoomGeneratorToolkit(sys_get_temp_dir() . '/coqui-doom-test-workspace');
    $names = array_map(fn(ToolInterface $t) => $t->name(), $toolkit->tools());

    expect($names)->toContain('doom_wad_inspect');
    expect($names)->toContain('doom_project');
    expect($names)->toContain('doom_asset');
    expect($names)->toContain('doom_build');
    expect($names)->toContain('doom_run');
    expect($names)->toContain('doom_toolchain');
});

// ── Project Integration Tests ────────────────────────────────────────────

/**
 * @return array<string, ToolInterface>
 */
function buildDoomTools(string $workspacePath): array
{
    $toolkit = new DoomGeneratorToolkit(workspacePath: $workspacePath);
    $tools = [];
    foreach ($toolkit->tools() as $tool) {
        $tools[$tool->name()] = $tool;
    }
    return $tools;
}

function createTempWorkspace(): string
{
    $dir = sys_get_temp_dir() . '/coqui-doom-workspace-' . bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);
    return $dir;
}

function removeTempWorkspace(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($dir);
}

test('doom_project init creates project directory structure', function () {
    $workspace = createTempWorkspace();

    try {
        $tools = buildDoomTools($workspace);
        $result = $tools['doom_project']->execute(['action' => 'init', 'name' => 'test-mod']);

        expect($result->status->value)->toBe('success');

        $decoded = json_decode($result->content, true);
        expect($decoded['created'])->toBeTrue();
        expect($decoded['name'])->toBe('test-mod');

        // Verify directories were created
        $projectDir = $workspace . '/doom-generator/projects/test-mod';
        expect(is_dir($projectDir))->toBeTrue();
        expect(is_dir($projectDir . '/graphics'))->toBeTrue();
        expect(is_dir($projectDir . '/sprites'))->toBeTrue();
        expect(is_dir($projectDir . '/sounds'))->toBeTrue();
        expect(is_dir($projectDir . '/build'))->toBeTrue();
        expect(is_file($projectDir . '/project.json'))->toBeTrue();
    } finally {
        removeTempWorkspace($workspace);
    }
});

test('doom_project list shows created projects', function () {
    $workspace = createTempWorkspace();

    try {
        $tools = buildDoomTools($workspace);
        $tools['doom_project']->execute(['action' => 'init', 'name' => 'mod-a']);
        $tools['doom_project']->execute(['action' => 'init', 'name' => 'mod-b']);

        $result = $tools['doom_project']->execute(['action' => 'list']);

        expect($result->status->value)->toBe('success');

        $decoded = json_decode($result->content, true);
        expect($decoded)->toHaveCount(2);

        $names = array_column($decoded, 'name');
        expect($names)->toContain('mod-a');
        expect($names)->toContain('mod-b');
    } finally {
        removeTempWorkspace($workspace);
    }
});

test('doom_project info returns project details', function () {
    $workspace = createTempWorkspace();

    try {
        $tools = buildDoomTools($workspace);
        $tools['doom_project']->execute(['action' => 'init', 'name' => 'info-test', 'source_port' => 'gzdoom']);

        $result = $tools['doom_project']->execute(['action' => 'info', 'name' => 'info-test']);

        expect($result->status->value)->toBe('success');

        $decoded = json_decode($result->content, true);
        expect($decoded['name'])->toBe('info-test');
    } finally {
        removeTempWorkspace($workspace);
    }
});

test('doom_project init with duplicate name returns error', function () {
    $workspace = createTempWorkspace();

    try {
        $tools = buildDoomTools($workspace);
        $tools['doom_project']->execute(['action' => 'init', 'name' => 'dupe']);
        $result = $tools['doom_project']->execute(['action' => 'init', 'name' => 'dupe']);

        expect($result->status->value)->toBe('error');
    } finally {
        removeTempWorkspace($workspace);
    }
});

test('doom_toolchain status returns toolchain info', function () {
    $workspace = createTempWorkspace();

    try {
        $tools = buildDoomTools($workspace);
        $result = $tools['doom_toolchain']->execute(['action' => 'status']);

        expect($result->status->value)->toBe('success');

        $decoded = json_decode($result->content, true);
        expect($decoded)->toHaveKeys(['toolchain', 'install_report', 'iwads', 'iwad_count']);
        expect($decoded['toolchain'])->toBeArray();
    } finally {
        removeTempWorkspace($workspace);
    }
});

test('doom_toolchain list_iwads returns empty when none installed', function () {
    $workspace = createTempWorkspace();

    try {
        $tools = buildDoomTools($workspace);
        $result = $tools['doom_toolchain']->execute(['action' => 'list_iwads']);

        expect($result->status->value)->toBe('success');

        $decoded = json_decode($result->content, true);
        expect($decoded['iwads'])->toBeArray();
    } finally {
        removeTempWorkspace($workspace);
    }
});
