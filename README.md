# Coqui Doom Generator Toolkit

Doom mod generation toolkit for [Coqui](https://github.com/AgentCoqui/coqui). Provides native WAD file parsing, mod project scaffolding, DeuTex build orchestration, Freedoom IWAD management, and source port launching — all sandboxed to the workspace.

## Requirements

- PHP 8.4+
- [Coqui](https://github.com/AgentCoqui/coqui) (auto-discovered via Composer)

### Optional External Tools

- **DeuTex** — WAD compilation and asset injection (`apt install deutex` / `brew install deutex`)
- **GZDoom** or **Chocolate Doom** — source ports for launching and testing builds

## Installation

```bash
composer require coquibot/coqui-toolkit-doom-generator
```

When installed alongside Coqui, the toolkit is **auto-discovered** via Composer's `extra.php-agents.toolkits` — no manual registration needed.

## Tools Provided

### `doom_wad_inspect`

Inspect any WAD file — parse headers, list lumps, search by name pattern, extract lump data.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | WAD file path relative to workspace |
| `filter` | string | No | Glob pattern to filter lump names (e.g. `MAP*`, `*SKY*`) |
| `show_header` | bool | No | Include header details in output (default: true) |

### `doom_project`

Create and manage mod projects with standardized directory structure.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `init`, `list`, `info` |
| `name` | string | Conditional | Project name (required for `init`, `info`) |
| `iwad` | string | No | Target IWAD name (default: `freedoom2.wad`) |
| `source_port` | string | No | Target source port (default: `gzdoom`) |
| `project_id` | string | No | Coqui project ID to link for sprint/artifact tracking |

### `doom_asset`

Add, remove, list, or extract assets (graphics, sounds, sprites, flats) in a project.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `add`, `remove`, `list`, `extract` |
| `project` | string | Yes | Project name |
| `lump_name` | string | Conditional | Lump name for add/remove/extract |
| `file_path` | string | Conditional | Source file path for add, output path for extract |
| `category` | enum | No | Asset category: `graphic`, `sound`, `sprite`, `flat`, `music`, `patch` |

### `doom_build`

Compile a project into a PWAD using DeuTex.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `project` | string | Yes | Project name to build |
| `output_name` | string | No | Custom output WAD filename |

### `doom_run`

Launch a build with a source port for testing.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `project` | string | No | Project name (uses latest build) |
| `wad_path` | string | No | Direct path to a WAD file |
| `source_port` | enum | No | `gzdoom`, `chocolate-doom`, `dsda-doom` |
| `iwad` | string | No | Override IWAD |
| `extra_args` | string | No | Additional CLI arguments for the source port |

### `doom_toolchain`

Check system toolchain status and manage Freedoom IWADs.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `status`, `install_freedoom` |

## Architecture

```
src/
├── DoomGeneratorToolkit.php      # ToolkitInterface entrypoint
├── Asset/
│   └── IwadManager.php           # Freedoom downloads + user IWAD management
├── Exception/
│   └── DoomGeneratorException.php
├── Project/
│   └── ProjectManager.php        # Mod project lifecycle
├── Runtime/
│   ├── DependencyChecker.php     # System tool detection
│   ├── DoomResult.php            # CLI result value object
│   └── DoomRunner.php            # Multi-binary CLI orchestrator
├── Tool/
│   ├── DoomAssetTool.php
│   ├── DoomBuildTool.php
│   ├── DoomProjectTool.php
│   ├── DoomRunTool.php
│   ├── DoomToolchainTool.php
│   └── DoomWadInspectTool.php
├── Support/
│   └── PathGuard.php             # Sandbox path validation
└── Wad/
    ├── WadHeader.php             # Header value object
    ├── WadLump.php               # Lump directory entry VO
    ├── WadReader.php             # Native PHP binary WAD parser
    ├── WadType.php               # IWAD/PWAD enum
    └── WadWriter.php             # PWAD binary writer
```

## License

MIT
