<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitDoomGenerator\Exception\DoomGeneratorException;
use CarmeloSantana\CoquiToolkitDoomGenerator\Wad\WadReader;

/**
 * Inspect any WAD file — parse headers, list lumps, search by name pattern.
 */
final readonly class DoomWadInspectTool
{
    public function __construct(
        private string $workspacePath,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'doom_wad_inspect',
            description: 'Inspect a WAD file — show header info (IWAD/PWAD, lump count), list all lumps, and optionally filter by name pattern. Use to examine any .wad file in the workspace.',
            parameters: [
                new StringParameter('path', 'WAD file path relative to workspace.', required: true),
                new StringParameter('filter', 'Glob pattern to filter lump names (e.g. "MAP*", "*SKY*", "D_*"). Case-insensitive.', required: false),
                new BoolParameter('show_header', 'Include header details in output (default: true).', required: false),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $path = trim((string) ($input['path'] ?? ''));
        $filter = trim((string) ($input['filter'] ?? ''));
        $showHeader = (bool) ($input['show_header'] ?? true);

        if ($path === '') {
            return ToolResult::error('The "path" parameter is required.');
        }

        $absolutePath = $this->resolvePath($path);

        try {
            $reader = new WadReader($absolutePath);
            $reader->parse();
        } catch (DoomGeneratorException $e) {
            return ToolResult::error($e->getMessage());
        }

        $output = [];

        if ($showHeader) {
            $output['header'] = $reader->summary();
        }

        $lumps = $filter !== '' ? $reader->findLumps($filter) : $reader->lumps();

        $lumpList = [];
        foreach ($lumps as $i => $lump) {
            $entry = [
                'index' => $i,
                'name' => $lump->name,
                'size' => $lump->size,
                'offset' => $lump->offset,
            ];

            if ($lump->isMarker()) {
                $entry['marker'] = true;
            }

            $lumpList[] = $entry;
        }

        $output['lumps'] = $lumpList;
        $output['total'] = count($lumpList);

        if ($filter !== '') {
            $output['filter'] = $filter;
            $output['total_in_wad'] = count($reader->lumps());
        }

        return ToolResult::success(json_encode($output, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
    }

    private function resolvePath(string $relativePath): string
    {
        $absolute = $this->workspacePath . '/' . ltrim($relativePath, '/\\');

        $realDir = realpath(dirname($absolute));
        if ($realDir !== false && !str_starts_with($realDir, $this->workspacePath)) {
            throw DoomGeneratorException::pathEscapesSandbox($relativePath);
        }

        return $absolute;
    }
}
