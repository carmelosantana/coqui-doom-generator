<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitDoomGenerator;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitDoomGenerator\Asset\IwadManager;
use CarmeloSantana\CoquiToolkitDoomGenerator\Project\ProjectManager;
use CarmeloSantana\CoquiToolkitDoomGenerator\Runtime\DoomRunner;
use CarmeloSantana\CoquiToolkitDoomGenerator\Tool\DoomAssetTool;
use CarmeloSantana\CoquiToolkitDoomGenerator\Tool\DoomBuildTool;
use CarmeloSantana\CoquiToolkitDoomGenerator\Tool\DoomProjectTool;
use CarmeloSantana\CoquiToolkitDoomGenerator\Tool\DoomRunTool;
use CarmeloSantana\CoquiToolkitDoomGenerator\Tool\DoomToolchainTool;
use CarmeloSantana\CoquiToolkitDoomGenerator\Tool\DoomWadInspectTool;

/**
 * Doom mod generation toolkit for Coqui.
 *
 * Provides 6 tools for creating custom Doom mods: WAD inspection,
 * project management, asset handling, building PWADs with DeuTex,
 * playtesting in source ports, and toolchain management.
 *
 * The toolkit uses Freedoom as a free-content IWAD base and supports
 * user-owned IWADs. Projects are sandboxed to the workspace directory.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * No credentials required.
 */
final class DoomGeneratorToolkit implements ToolkitInterface
{
    private readonly DoomRunner $runner;
    private readonly IwadManager $iwads;
    private readonly ProjectManager $projects;

    public function __construct(
        private readonly string $workspacePath,
    ) {
        $basePath = $this->workspacePath . '/doom-generator';
        $this->runner = new DoomRunner($this->workspacePath);
        $this->iwads = new IwadManager($basePath);
        $this->projects = new ProjectManager($basePath);
    }

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        if ($workspacePath === false || $workspacePath === '') {
            $workspacePath = getcwd() . '/.workspace';
        }

        return new self(workspacePath: $workspacePath);
    }

    public function tools(): array
    {
        return [
            (new DoomWadInspectTool($this->workspacePath))->build(),
            (new DoomProjectTool($this->projects))->build(),
            (new DoomAssetTool($this->projects))->build(),
            (new DoomBuildTool($this->runner, $this->projects))->build(),
            (new DoomRunTool($this->runner, $this->projects, $this->iwads))->build(),
            (new DoomToolchainTool($this->runner, $this->iwads))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <DOOM-GENERATOR-GUIDELINES>
            ## Doom Mod Generation Toolkit

            You have 6 tools for creating custom Doom mods from scratch.

            ### Tool Selection Guide

            | Task | Tool | When to Use |
            |------|------|-------------|
            | Inspect a WAD file | `doom_wad_inspect` | Examine WADs, list lumps, search for specific assets |
            | Create/manage projects | `doom_project` | Initialize mod projects, list existing, get info |
            | Manage project assets | `doom_asset` | Add/remove/list graphics, sounds, sprites, etc. |
            | Build a PWAD | `doom_build` | Compile project assets into a playable .wad file |
            | Playtest a mod | `doom_run` | Launch a built mod in GZDoom/Chocolate Doom |
            | Manage toolchain | `doom_toolchain` | Check tool availability, install Freedoom IWADs |

            ### Recommended Workflow (Coqui-Integrated)

            Doom mod projects integrate with Coqui's project, sprint, and artifact system for structured development:

            1. **Create a Coqui project:** `project_create(title: "My Doom Mod", slug: "my-doom-mod")`
            2. **Set it active:** `/projects my-doom-mod` — injects context into all agent prompts
            3. **Plan sprints:** Create one sprint per milestone:
               - `sprint_create(project_id: "...", title: "Core Assets", acceptance_criteria: '["Wall textures added", "Enemy sprites created", "Sound effects present"]')`
               - `sprint_create(project_id: "...", title: "First Playable Build", acceptance_criteria: '["PWAD builds without errors", "Mod loads in GZDoom", "Custom assets visible in-game"]')`
            4. **Check toolchain:** `doom_toolchain(action: "status")` — verify DeuTex and source ports
            5. **Get Freedoom:** `doom_toolchain(action: "install_freedoom")` — download free IWAD if needed
            6. **Init mod project:** `doom_project(action: "init", name: "my-doom-mod", project_id: "<coqui_project_id>")` — links to Coqui project
            7. **Add assets:** `doom_asset(action: "add", project: "my-doom-mod", file_path: "...", category: "graphic")`
            8. **Build WAD:** `doom_build(project: "my-doom-mod")` — response includes `coqui_project_id` for artifact tracking
            9. **Track builds as artifacts:** `artifact_create(type: "data", project_id: "...", sprint_id: "...", title: "my-doom-mod.wad build #1")`
            10. **Review sprint:** `sprint_transition(id: "...", status: "review")` when acceptance criteria are met
            11. **Playtest:** `doom_run(project: "my-doom-mod")`

            For automated multi-stage development, use loops:
            ```
            loop_start(definition: "harness", goal: "Create a Doom mod with custom wall textures and enemy sprites", project_slug: "my-doom-mod")
            ```

            ### Standalone Workflow (Quick Start)

            For quick one-off mods without project tracking:

            1. `doom_toolchain(action: "status")`
            2. `doom_project(action: "init", name: "quick-mod")`
            3. `doom_asset(action: "add", project: "quick-mod", ...)`
            4. `doom_build(project: "quick-mod")`
            5. `doom_run(project: "quick-mod")`

            ### Project Structure

            Each project lives in `{workspace}/doom-generator/projects/{name}/` with:
            - `graphics/` — UI elements, title screens, status bar
            - `sprites/` — animated game objects (enemies, items, weapons)
            - `flats/` — floor/ceiling textures (64x64)
            - `sounds/` — sound effects (WAV/raw PCM)
            - `music/` — music tracks (MIDI/MUS)
            - `patches/` — wall textures (combined via TEXTURE1/2)
            - `scripts/` — ZScript, DECORATE, ACS, DEHACKED
            - `lumps/` — raw lumps (MAPINFO, LANGUAGE, etc.)
            - `build/` — compiled output WADs

            ### WAD Format Basics

            - **IWAD** (Internal WAD): Complete game data (Doom, Doom II, Freedoom)
            - **PWAD** (Patch WAD): Mod data that overrides/adds to IWAD content
            - Lumps are named resources (max 8 chars, uppercase) — textures, maps, sounds, etc.
            - Use `doom_wad_inspect` to examine any WAD's structure

            ### IWADs

            - **Freedoom** is the default IWAD — free-content, BSD-licensed replacement for Doom/Doom II
            - Use `doom_toolchain(action: "register_iwad", path: "...")` to register user-owned IWADs
            - Use `doom_toolchain(action: "list_iwads")` to see available IWADs

            ### Asset Naming Conventions

            | Asset Type | Naming Convention | Examples |
            |-----------|-------------------|----------|
            | Sprites | 4-char name + frame/rotation | TROO (enemy), POSS (zombie) |
            | Flats | Up to 8 chars | FLOOR4_8, CEIL3_5 |
            | Wall patches | Up to 8 chars | BRICK1, METAL2 |
            | Sounds | DS + name or DP + name | DSPISTOL, DSSHOTGN |
            | Music | D_ + name | D_RUNNIN, D_E1M1 |

            ### Best Practices

            - Always start with `doom_toolchain(action: "status")` in a new session
            - Link mod projects to Coqui projects (`project_id` param) for sprint/artifact tracking
            - Use `doom_wad_inspect` to study existing WADs for reference
            - Keep lump names ≤ 8 characters, uppercase, alphanumeric + underscore
            - Use `doom_project(action: "info")` to check project status before building
            - Track each successful build as an artifact for versioning
            - Build and test frequently — small iterative changes work best
            - Freedoom provides a full set of replacement assets for all Doom II content
            </DOOM-GENERATOR-GUIDELINES>
            GUIDELINES;
    }
}
