# Kalimera ☀️

Interactive installer that scaffolds a fresh Laravel application — one
guided CLI flow from `laravel new` to a fully configured, quality-checked, Sail-running app with all the tools you need.

Instead of a boilerplate — a frozen copy of someone else's choices that goes stale — kalimera
automates the initialization step itself: a fresh, current Laravel application prepared exactly
the way you want it, the same way every time. Your package set is defined once in
`kalimera.config.json` (the built-in catalog is the Spatie collection — replace it with your
own), Pint + PHPStan + Rector and Sail are wired from the first commit, every choice is made
at once upfront, and the whole run is unattended — driven by you or by your coding agent
through the [agent skill](#agent-skill). A ready-to-build project in minutes, no manual
setup each time.

## Requirements

- PHP 8.4+, Composer, the [Laravel installer](https://laravel.com/docs/installation), Docker, git

## Installation

### Global (recommended)

Install once, use `kalimera` from anywhere — same mechanism as the `laravel` installer.

```bash
composer global require labrodev/kalimera
```

Make sure composer's global bin directory (`~/.composer/vendor/bin` or
`~/.config/composer/vendor/bin`) is in your `PATH` — if the `laravel` command works, it already is.

#### Working from a local checkout

Developing kalimera itself? Point global composer at your working copy with a path repository
instead of Packagist (the symlink means every local change is instantly live):

```bash
composer global config repositories.kalimera '{"type": "path", "url": "/path/to/laravel-kalimera", "options": {"symlink": true}}'
composer global require labrodev/kalimera:@dev
```

(`:@dev` is needed because a path repository has no tagged releases — the package only exists
as `dev-main`, and composer's default stability is `stable`.) A path repository is canonical,
so it silently shadows the Packagist version even after you're done — switch back with:

```bash
composer global config --unset repositories.kalimera
composer global require labrodev/kalimera
```

### Local (no global install)

```bash
cd laravel-kalimera && composer install
```

Then call the binary by its full path from wherever the new app should live:

```bash
cd ~/www
/path/to/laravel-kalimera/bin/kalimera new
```

> The app is created in the **current working directory** — don't run kalimera from inside
> this repo unless you want the app scaffolded into it.

## Usage

```bash
kalimera new                   # prompts for everything, creates ./<app-name>
kalimera new my-app            # skip the name prompt
kalimera new ~/www/my-app      # a path works too — the app is created exactly there
```

Options:

| Flag | Effect |
|---|---|
| `--dry-run` | Print every command without executing anything |
| `--defaults` | Skip all prompts and accept the preselected answers |
| `--continue` | Resume into an existing app directory after a failed run — the answers saved in its `.kalimera.json` are reused, no prompts, and the steps that file records as done are skipped so the run picks up where it stopped. The file is written after `laravel new`, gitignored, and removed when the scaffold completes. Editing the answers before resuming only affects steps that have *not* been checkpointed — a step already listed in `completedSteps` is skipped whatever its answers now say, so delete it from the list as well to have the edit take effect |
| `--config=path` | Load the preselected answers and the additional-packages catalog from a JSON config — see [Configuration](#configuration). `kalimera.config.json` in the current directory is picked up automatically |
| `--log[=path]` | Write an append-only transcript of steps, commands and outcomes to a log file (defaults to `kalimera.log` inside the new application, gitignored there) |
| `--verbose`, `-v` | Stream the raw output of every command instead of condensing it to a progress line. Off by default: the Sail image build alone is tens of thousands of apt lines, which buries the installer's own steps. A failed command always replays its last 40 lines regardless, and `--log` captures everything either way |

## What it does

1. **Prompts** for everything upfront: app name, starter kit (React / Vue / Livewire / Svelte /
   none, with optional manual Inertia), ecosystem packages (Horizon, Fortify, Laravel AI,
   Nightwatch), Sail services,
   PHP version constraint (default `^8.5`), quality tools, additional packages, Postmark, GitHub
   repos for Boost skills, and any extra packages — then shows a summary and one final confirm.
2. `laravel new` with Pest and git, no interaction.
3. Installs **Sail** with the chosen services, pins the Sail runtime to the chosen PHP version,
   remaps any host ports that are already busy (`APP_PORT`, `FORWARD_DB_PORT`, …) so parallel
   projects never clash, and boots the containers (`sail up -d --wait`). Everything after this point runs **through
   Sail**, so the host PHP version never matters.
4. Restricts `require.php` in composer.json to the chosen constraint.
5. Sets up **Pint / PHPStan (Larastan + IDE Helper) / Rector** with ready-made configs
   (`pint.json`, `phpstan.neon.dist`, `rector.php`) and composer scripts: `pint:dry`,
   `pint:fix`, `phpstan`, `phpstan-clear`, `ide-helper`, `rector:dry`, `rector:fix` and the
   aggregate `quality`. Generated ide-helper files are gitignored.
6. Installs the chosen **additional packages** and publishes their configs/migrations. The
   built-in catalog is the Spatie collection (`laravel-data`, `laravel-view-models`,
   `laravel-query-builder`, `laravel-backup`, `laravel-permission`, `laravel-activitylog`,
   `laravel-translatable`) — replace it with your own via [Configuration](#configuration).
7. Optionally installs **Laravel Boost** (on by default): you pick the AI agents (Claude Code, Cursor, Codex, Copilot, …)
   upfront — kalimera preconfigures `boost.json` and runs `boost:install --guidelines --skills
   --mcp --no-interaction`, fully unattended. (Select no agents to answer Boost's own prompts
   instead.) Then pulls your skills from GitHub via `boost:add-skill <owner/repo> --all` — one
   run per repository, so you can list several space- or comma-separated at the prompt. Finishes
   with `boost:update --no-discover --no-interaction` so the guidelines and skills are the latest
   guidance rather than whatever the installed release bundled (a warning, never a failure).
8. Optionally sets up the **Postmark SDK** (`wildbit/postmark-php`) with `postmark:push` /
   `postmark:pull` composer scripts and a `POSTMARK_API_KEY` env placeholder.
9. Optionally scaffolds the **Core structure**: `src/{Domain,Shared,Support,Feature,Infrastructure}`
   with `.gitkeep` files, mapped to a PSR-4 namespace of your choice (`Core` by default).
10. Records the **dependency audit trust file** with [Laravel Vet](https://github.com/laravel/vet)
    (chosen at the quality-tools prompt, on by default): requires `laravel/vet`, allows its
    plugin in `composer.json`, and writes `vet.json` from every package the scaffold installed.
    Vet is a composer plugin, so from here on each `composer install`, `update` and `require`
    shows the code it is about to write into `vendor/` and fails on a version nobody has read —
    review it with `sail composer vet` in a terminal, which will hand the diffs to your coding
    agent if you ask. Adds a `vet` composer script and appends `@vet` to `quality`, so an
    unreviewed dependency change fails the gate. Commit `vet.json`. No `minimum-release-age` is
    set: the floor rejects releases younger than it *even when trusted*, so a fresh application
    would arrive failing its own audit — [TROUBLESHOOTING.md](TROUBLESHOOTING.md) has the recipe
    for turning it on deliberately.
11. Installs the **AI-agent guard**: publishes `app/Providers/AgentGuardServiceProvider.php` and
    registers it last in `bootstrap/providers.php`, so destructive database commands
    (`db:wipe`, `migrate:fresh/refresh/reset/rollback`) are prohibited whenever an AI agent is
    driving the app — exactly as they already are in production. Detection comes from
    `laravel/pao`'s `AgentDetector`, which ships with new Laravel apps by default and recognizes
    Claude Code, Cursor, Codex, Copilot, Gemini and others. Registration order matters and the
    provider must boot **after** `AppServiceProvider`; see [TROUBLESHOOTING.md](TROUBLESHOOTING.md).
12. Finalizes: migrates, `npm install`, formats the fresh skeleton with Pint + Rector,
    baselines PHPStan if needed so `composer quality` starts green, and commits.

## Configuration

Kalimera works with zero configuration — the built-in setup is the curated Labrodev stack.
To adapt it to your own, drop a `kalimera.config.json` next to where you run `kalimera`
(or point at one with `--config=path`). Everything in it is optional; whatever you omit
keeps the built-in behavior.

```json
{
    "$schema": "https://raw.githubusercontent.com/labrodev/laravel-kalimera/main/schema/kalimera.config.schema.json",
    "preselected": {
        "starterKit": "vue",
        "sailServices": ["mysql", "redis", "mailpit"],
        "phpConstraint": "^8.4",
        "boostSkillRepos": ["acme/boost-skills"],
        "coreNamespace": "Acme\\Core"
    },
    "additionalPackages": [
        {
            "package": "spatie/laravel-medialibrary",
            "label": "medialibrary — file attachments for Eloquent models",
            "preselected": true,
            "publishProviders": ["Spatie\\MediaLibrary\\MediaLibraryServiceProvider"]
        },
        {
            "package": "barryvdh/laravel-debugbar",
            "label": "debugbar — in-browser debug toolbar",
            "dev": true
        }
    ]
}
```

- **`preselected`** — the pre-chosen answers of every prompt, and exactly what `--defaults`
  installs unattended. Keys mirror the prompts: `starterKit`, `installInertia`, `aroundPackages`,
  `sailServices`, `phpConstraint`, `qualityTools`, `installPostmark`, `installBoost`
  (`false` skips the Boost step and the two questions behind it), `coreNamespace`
  (`null` skips the Core scaffold), `boostAgents`, `boostSkillRepos`, `extraPackages`,
  `extraDevPackages`.
- **`additionalPackages`** replaces the built-in Spatie catalog shown by the
  "additional packages" prompt. Each entry takes a composer `package` (a version constraint
  like `vendor/package:^2.0` works), an optional prompt `label`, `dev` to require with
  `--dev`, `preselected` to have it chosen by default, and `publishProviders` to run
  `vendor:publish` for after installation.

The built-in setup itself lives in
[schema/kalimera.config.schema.json](schema/kalimera.config.schema.json): each property's
enum lists the available options — what is there — and the `default` values of `preselected`
and `additionalPackages` are the built-in choices — what is chosen. Kalimera reads it on
start, so editing that file changes what the installer offers out of the box (your fork,
your stack), while a `kalimera.config.json` overrides it per project. Referencing it via
`$schema` gives your editor completion for every option. Validation happens in kalimera
itself: every loaded file is checked on start and unknown keys or invalid values are
refused with a precise error, so a typo fails before anything is installed, not halfway
through.

## Agent skill

The repo ships a ready-made agent skill at [skills/laravel-kalimera/](skills/laravel-kalimera/SKILL.md)
that teaches AI coding agents to drive kalimera end-to-end. The agent becomes the prompt layer:
it re-asks kalimera's full prompt set in its own interface (starter kit, Sail services, ecosystem
and Spatie packages, PHP constraint, quality tools, Core structure, Boost agents, …), encodes the
answers into a config JSON, and runs the installer unattended with `--defaults` — kalimera's own
prompts would die in the agent's non-TTY shell. It then verifies the result and resumes failed
runs. The skill is plain Markdown in the open [Agent Skills](https://agentskills.io) format, so
any skills-aware tool can consume it.

- **Claude Code** — one symlink and the skill applies to every session on the machine. After
  the global install above, link through composer's vendor directory — it keeps working once
  the package is on Packagist, and `composer global update` refreshes the skill content:

  ```bash
  ln -s ~/.composer/vendor/labrodev/kalimera/skills/laravel-kalimera ~/.claude/skills/laravel-kalimera
  ```

  (`~/.config/composer/vendor/...` on Linux; linking straight to a cloned repo works too.)

- **Cursor, Codex, and friends** — they support the same format: copy or symlink
  `skills/laravel-kalimera/` into the tool's skills directory (see its Agent Skills docs),
  or paste `SKILL.md` into its global rules/instructions.

## Layout

```
bin/kalimera        entry point
src/                installer code (Kalimera\)
  KalimeraInstaller.php   the orchestrator behind `kalimera new`
  Contracts/        interfaces: Pipeline, PortChecker, ProcessRunner
  Exceptions/       one exception class per failure mode
  Payloads/         immutable data carriers: Argument, InstallerOption
  Pipelines/        the installation steps, executed in order
  Services/         invokable and multi-op helpers (files, shell, sail, prompts)
schema/             kalimera.config.schema.json — the built-in setup and config file reference
skills/             agent skill that teaches AI agents to drive kalimera (see Agent skill)
templates/          pint.json, phpstan.neon.dist, rector.php shipped into generated apps
tests/              Pest suite (Kalimera\Tests\)
```

## Quality & tests

The package dogfoods the same tooling it installs into new applications:

```bash
composer test       # the full Pest suite
composer quality    # rector:dry + pint:dry + phpstan (level 8) + tests
```

Individual scripts: `pint:dry` / `pint:fix`, `rector:dry` / `rector:fix`, `phpstan`,
`phpstan-clear`, `test` and `test:coverage`.

The suite is split into three Pest test suites:

```
tests/Unit          one test file per src/ class (Services, Pipelines, Payloads)
tests/Feature       in-process dry-run snapshot: the exact command sequence `kalimera new
                    --dry-run --defaults` produces, plus usage/exit-code behavior
tests/Arch          architecture rules: strict types, readonly classes, no debug output,
                    pipeline contract, Services/Payloads layer boundaries
```

Steps talk to the outside world only through the `ProcessRunner`, `PortChecker` and
`ExecutableFinder` seams, so the whole installer runs in-process against fakes
(`tests/Fakes/`) — no Docker, network or `laravel` binary needed to test it.

### Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request:

| Job | What it covers |
|---|---|
| Quality matrix | The full suite on PHP 8.4 and 8.5 across Ubuntu and macOS. Pint, PHPStan and Rector are platform-independent, so they run on Ubuntu only; macOS runs the tests because it is the platform kalimera is actually used on, and the one whose quirks the process handling works around — VirtioFS write visibility, and a TTY-mode `wait()` that misreports success |
| Lowest dependencies | The suite against the lowest versions every `composer.json` constraint allows. Resolution always picks the newest, so without this the declared floors are never executed — this job is what caught `mockery ^1.6` emitting PHP 8.4 deprecations at its lower bound |
| Coverage | `composer test:coverage`, informational rather than a gate — the number exists so gaps are visible when deciding what to test next, not so a PR can be blocked on a decimal point |

Because every test fakes the process runner, the suite proves kalimera emits the right
commands but never that they succeed. `.github/workflows/nightly-scaffold.yml` is the run
that does — nightly rather than per-PR, since a cold Sail image build is 10–15 minutes:

- **Scaffold a real application** — `kalimera new nightly-app --defaults --log` against real
  Docker, then asserts the containers are up and `migrate:status` answers. `laravel new`
  and `sail:install` merely succeeding is not the bar; the real failures land after that.
- **Resume a failed scaffold** — deliberately breaks a run with an unresolvable package,
  asserts the finished steps were checkpointed and the failed one was not, fixes the saved
  answers the way a user would, resumes, and checks the completed steps were skipped while
  the containers were started anyway. What the suite cannot prove is that a half-scaffolded
  application on disk is one the remaining steps can finish.

Both jobs upload their `--log` transcript as an artifact, which is the only record of what
the child processes printed and exactly what a failed nightly needs. Every bug found in
real use so far — an orphaned Postgres sequence, an inherited volume, a frozen progress
line — survived the faked-runner suite and only showed up in a run like these.
