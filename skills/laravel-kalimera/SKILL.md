---
name: laravel-kalimera
description: Scaffold complete Laravel applications with the kalimera CLI installer. Use when creating a new Laravel project, starting an app from scratch, or resuming a failed kalimera run. Ask stack preferences, then run non-interactively: name + --defaults + config JSON.
---

# Kalimera Installer

One command scaffolds a Sail-running, migrated, quality-green, git-committed Laravel app. NEW projects only. Prompts die in non-TTY shells — always pass the app name and `--defaults`.

## The Recipe

```bash
# 1. Go to the PARENT directory — the app is created relative to CWD
cd ~/www

# 2. Scaffold non-interactively (name positional, --defaults mandatory, --log for post-mortems)
kalimera new my-app --defaults --log

# 3. Unsure? Rehearse first — prints the full command plan, touches nothing
kalimera new my-app --dry-run --defaults
```

**Rule**: The positional name is mandatory. Without it kalimera prompts for a name — even with `--defaults` — and dies with a bare `Required.` in a non-TTY shell.

**Rule**: Run from the intended parent directory. Never from inside the kalimera repo (the app scaffolds into it) or inside an existing app.

**Rule**: A real scaffold takes 10+ minutes (Docker build, composer, npm). Raise the command timeout or run it in the background — a default 2-minute timeout kills it mid-pipeline.

Bare `--defaults` gives: React kit, the full ecosystem set (Horizon, Fortify — auto-skipped when the kit already ships it — Laravel AI, Nightwatch), pgsql + redis, PHP `^8.5`, Pint + PHPStan + Rector, `Core` src/ scaffold, Boost for claude_code/cursor/codex — and zero Spatie catalog packages (a catalog entry installs only with `"preselected": true`; see Custom Setups).

## Collect Preferences First

| Situation | Do |
|-----------|----|
| User stated stack choices | Map them to config keys, ask only about the gaps, write a config JSON, run with `--config=` |
| No preferences stated | Ask the full prompt set (below), then config JSON + `--config=` |
| User said "defaults" / "don't ask" | Bare `--defaults`, no questions |

Mirror kalimera's own prompts — cover every topic, batched into as few rounds as the interface allows (4-question batches beat one-by-one). Show the default in each question; the moment the user says "defaults for the rest", stop asking and keep the remaining defaults.

| Ask about | Options (preselected first) | Config key |
|-----------|-------------------------|------------|
| App name | plain name (created in CWD) or a path like `~/www/my-app` | positional argument |
| Starter kit | react, vue, livewire, svelte, none | `starterKit` |
| Manual Inertia — only when kit is `none` | no / yes (wiring stays manual either way) | `installInertia` |
| Ecosystem packages | all of horizon, fortify, ai, scout, nightwatch — deselect to trim | `aroundPackages` |
| Sail services | pgsql + redis, mysql, mailpit, meilisearch, minio | `sailServices` |
| PHP constraint | ^8.5, ^8.4, custom | `phpConstraint` |
| Quality tools | pint + phpstan + rector + vet, or fewer | `qualityTools` |
| Spatie packages | none, or per-package picks from the catalog | `additionalPackages` |
| Core src/ scaffold | yes as `Core`, custom namespace, or skip | `coreNamespace` |
| Postmark SDK | no / yes | `installPostmark` |
| Laravel Boost | yes / no — skip it and the two rows below do not apply | `installBoost` |
| Boost agents | claude_code + cursor + codex, or picks from the 13 | `boostAgents` |
| Boost skills repos | none, or `owner/repo` list | `boostSkillRepos` |
| Extra packages | none, or composer names (dev variants too) | `extraPackages`, `extraDevPackages` |

Skip the Inertia row unless the kit is `none`; picking `horizon` implies `redis`. When question slots are tight, fold the low-signal tail (PHP constraint, quality tools, Boost agents/repos, extra packages) into one closing "anything else to tweak? — otherwise defaults" question — offered, not silently defaulted.

## Preflight

| Check | Command |
|-------|---------|
| kalimera installed | `command -v kalimera` |
| Required binaries | `command -v php composer laravel docker git` |
| Docker daemon answering | `docker info` |

Kalimera hard-fails on a missing binary or a dead daemon. `--dry-run` downgrades the daemon check to a warning.

### If kalimera is missing

Install it globally from Packagist:

```bash
composer global require labrodev/kalimera
```

Composer's global bin directory (`~/.composer/vendor/bin`, or `~/.config/composer/vendor/bin` on Linux) must be on `PATH` — if `command -v laravel` works, it already is.

Working from a local checkout of kalimera itself (on Labrodev machines it lives at `~/www/laravel-kalimera`; if it is not there, ask the user where the repo is rather than guessing):

```bash
# Substitute the ABSOLUTE repo path — composer does not expand ~ inside repository urls
composer global config repositories.kalimera '{"type": "path", "url": "/absolute/path/to/laravel-kalimera", "options": {"symlink": true}}'
composer global require labrodev/kalimera:@dev
```

A path repository is canonical and keeps shadowing Packagist afterwards — undo it with `composer global config --unset repositories.kalimera`. Zero-install alternative: run `composer install` once inside the repo, then call `<repo>/bin/kalimera` by full path.

## Flags

| Flag | Effect | Agent note |
|------|--------|------------|
| `--defaults` | Skip every prompt and the final confirm | Mandatory in non-TTY shells |
| `--dry-run` | Print the full command plan, execute nothing | Validates the config; works while Docker is down |
| `--continue` | Resume a failed run from the app's `.kalimera.json` — its saved answers, skipping the steps it records as done | Still needs the name and `--defaults` |
| `--config=path` | Load preselected answers + package catalog from JSON | The only customization mechanism |
| `--log[=path]` | Append-only transcript | Bare `--log` writes `<app>/kalimera.log` (gitignored) |
| `--verbose`, `-v` | Stream raw command output instead of a condensed progress line | Rarely worth it — the Sail build alone is tens of thousands of lines. `--log` captures everything either way, and a failed command replays its last 40 lines regardless |

**Rule**: There is no `--no-interaction`, no env vars, no per-prompt flags, no JSON output. Custom setup means a config JSON — nothing else.

## Custom Setups (config JSON)

`kalimera.config.json` in the CWD is applied automatically; `--config=path` points anywhere. A config left in a shared parent directory (e.g. `~/www`) silently shapes every later scaffold run from there — write one-off configs to a scratch path and pass `--config=` explicitly.

Minimal example — "Vue, MySQL, Horizon only":

```json
{
    "preselected": {
        "starterKit": "vue",
        "aroundPackages": ["horizon"],
        "sailServices": ["mysql"]
    }
}
```

**Rule**: Omitted keys keep the built-in defaults. Unknown keys or invalid values abort with exit 1 before anything installs — do not invent keys.

**Rule**: Selecting `horizon` silently adds `redis` to `sailServices`.

All `preselected` keys:

| Key | Options | Preselected |
|-----|--------|---------|
| `starterKit` | `react`, `vue`, `livewire`, `svelte`, `none` | `"react"` |
| `installInertia` | bool — only with `starterKit: none`; wiring stays manual | `false` |
| `aroundPackages` | `horizon`, `fortify`, `ai`, `scout`, `nightwatch` | all five |
| `sailServices` | `pgsql`, `redis`, `mysql`, `mailpit`, `meilisearch`, `minio` | `["pgsql", "redis"]` |
| `phpConstraint` | version constraint string | `"^8.5"` |
| `qualityTools` | `pint`, `phpstan`, `rector`, `vet` | all four |
| `coreNamespace` | namespace string; `null` skips the src/ scaffold | `"Core"` |
| `installPostmark` | bool | `false` |
| `installBoost` | bool — `false` skips the Boost step and the two keys below | `true` |
| `boostAgents` | `claude_code`, `cursor`, `codex` + 10 more | those three |
| `boostSkillRepos` | list of `owner/repo` | `[]` |
| `extraPackages`, `extraDevPackages` | composer package names | `[]` |

### Enabling Spatie catalog packages

Bare `--defaults` installs zero catalog packages. Setting `additionalPackages` replaces the entire built-in catalog — re-declare only the entries you want, each with `"preselected": true` (that flag is what `--defaults` installs) and its `publishProviders`:

```json
{
    "additionalPackages": [
        {
            "package": "spatie/laravel-data",
            "preselected": true,
            "publishProviders": ["Spatie\\LaravelData\\LaravelDataServiceProvider"]
        }
    ]
}
```

Built-in catalog (all `spatie/`): laravel-data, laravel-view-models, laravel-query-builder, laravel-backup, laravel-permission, laravel-activitylog, laravel-translatable. The full catalog with every provider string is in `references/examples.md`.

Workflow: write the JSON → rehearse with `kalimera new app --dry-run --defaults --config=...` (full validation + printed plan) → real run.

## Verify Success

```bash
# 1. Exit code is the primary signal (binary: 0 success, 1 failure)
kalimera new my-app --defaults --log || echo "FAILED"

# 2. Completion marker: the resume-state file is deleted only when the scaffold finished
test ! -f my-app/.kalimera.json && echo "completed"

# 3. Containers actually running
cd my-app && ./vendor/bin/sail ps

# 4. Strongest end-to-end proof: the last pipeline step made this commit
git log --oneline -1    # chore: scaffold application with kalimera
```

**Rule**: Exit 0 means the scaffold completed, not that quality is green — `composer quality` runs as a soft step during finalize. For a strict check run `./vendor/bin/sail composer quality`.

## Failure and Resume

On failure kalimera prints the error plus `Scaffolding stopped. Fix the issue above and re-run, or continue manually inside the app directory.` and exits 1. With `--log`, read `<app>/kalimera.log`; if the app directory was never created, the transcript lands in the CWD as `kalimera-<timestamp>.log`.

```bash
# Good — parent dir, positional name, both flags
cd ~/www && kalimera new my-app --continue --defaults

# Bad — prompts for the name and dies with "Required." in a non-TTY shell
kalimera new --continue
```

- `--continue` reuses the answers saved under `answers` in `<app>/.kalimera.json` and skips every step listed under `completedSteps` in the same file, so the run picks up at the step that broke instead of replaying the plan. The file is written right after `laravel new`, gitignored, and deleted once the whole plan has finished. A file left by an earlier kalimera release (flat answers plus `.kalimera-steps.json`) is still read.
- Starting the Sail containers always re-runs, checkpoint or not: the record says they were started once, not that they are up now. Seeing `sail up -d --wait` again on a resume is correct.
- **Rule**: changing an answer before resuming only reaches steps that have NOT been checkpointed. Editing `answers` in `.kalimera.json` to drop a package that will not resolve does nothing if its step already completed — delete that step from `completedSteps` too. Emptying `completedSteps` replays everything.
- Fix the cause in `.kalimera.json`, not in the config JSON: a resume reads its answers from the saved state file, and `--config=` no longer applies.
- `--continue` resumes kalimera's OWN interrupted runs only. No `.kalimera.json` — nothing to resume. A `.kalimera.json` whose answers are missing or unreadable stops the resume with `Cannot resume …`: rebuilding the plan from new answers would skip steps that ran under the old ones. Restore the answers, or delete the directory and start over. Never point kalimera at an arbitrary existing project: "add X to an existing app" is a composer/artisan task, not a kalimera task.
- Deleted the app directory to start over? `--continue` then has nothing to resume and runs as a plain fresh scaffold, including clearing any Docker project an earlier run left at that exact path. Passing it defensively is safe; it is not a way to re-enter a directory that is gone.
- A step that re-runs republishes its template. If a published config (`pint.json`, `phpstan.neon.dist`, `rector.php`) was edited while diagnosing the failure, kalimera keeps the edited version as `<file>.bak` and warns — nothing is lost, but the live file is the template again.
- `Another kalimera run is already working on <path>` — a concurrent run holds the lock; it dies with that process. Wait for it, or kill the other kalimera.

## The Generated App and AgentGuard

Every generated app registers `AgentGuardServiceProvider` last in `bootstrap/providers.php`. It calls `DB::prohibitDestructiveCommands()` whenever an AI agent drives artisan (detected via `laravel/pao`) or the app runs in production — so the agent that just scaffolded the app is itself refused destructive commands. This is by design. Never bypass, unregister, or reorder the provider.

| Blocked for agents | Use instead |
|--------------------|-------------|
| `migrate:fresh`, `migrate:refresh` | `sail artisan migrate` (forward-only) |
| `migrate:reset`, `migrate:rollback`, `db:wipe` | ask the human to run it |

Working in the app:
- Everything runs through Sail — `./vendor/bin/sail artisan ...`, `./vendor/bin/sail composer ...`. The scaffold itself needs PHP 8.3+ and Composer 2.2+ on the host (it downloads packages there); inside the app, composer.json pins `config.platform.php` to the container's PHP, so a host `composer update` still resolves for the container.
- Ready-made composer scripts: `pint:dry` / `pint:fix`, `phpstan` / `phpstan-clear`, `rector:dry` / `rector:fix`, `ide-helper`, `vet`, aggregate `quality` — plus `postmark:push` / `postmark:pull` when Postmark was chosen.
- Vet gates dependencies: `sail composer require <package>` now fails with "packages are not trusted" until someone reads the change. Run `sail composer vet` **in a terminal** — the composer plugin never asks, and non-interactive runs can only report. Vet can hand the diff to a coding agent from that prompt; you decide what it records. `vet.json` is committed. Kalimera sets no `minimum-release-age`, so nothing is held back by age alone. The first `vet.json` is trust-on-first-use: kalimera downloads every package with plugins off, so vet never gated the initial set, and `vet --init` then records all of it as trusted. If that matters, review the scaffold's package list before building on it.
- Printed next steps after success: `cd <app>`, `./vendor/bin/sail up -d`, `./vendor/bin/sail composer quality`.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `Required.` then nothing | A prompt fired in a non-TTY shell | Re-run with positional name + `--defaults` from the parent dir |
| `all predefined address pools have been fully subnetted` | Orphaned Sail networks from earlier scaffolds | `docker network prune`, then `--continue --defaults` |
| `There are no commands defined in the "boost" namespace` | Composer flake skipped `laravel/boost` | `cd <app> && ./vendor/bin/sail composer require laravel/boost --dev`, then resume |
| `port is already allocated` on a later `sail up` | Port probe only sees currently listening sockets | Edit `.env` of the app you are NOT running (`APP_PORT`, `VITE_PORT`, `FORWARD_*_PORT`) |
| PHPStan exit 1 with zero output | Deleted `src/` still listed in configs | Strip `src` from `phpstan.neon.dist` and `rector.php` |
| `packages are not trusted` on any composer command | Vet has not recorded the new versions | `sail composer vet` in a terminal (it cannot prompt from a script); never delete `vet.json` to get past it |
| `migrate:fresh` works when it should refuse | AgentGuard not last in providers | Move it last in `bootstrap/providers.php`; verify it refuses again |
| A resume ignored an answer you changed | That step is already checkpointed | Delete it from `completedSteps` in `<app>/.kalimera.json` as well as fixing `answers` |
| `Cannot resume <path>: .kalimera.json is there but the answers …` | The saved answers were deleted or corrupted after steps ran | Restore `answers` in `.kalimera.json`, or delete the directory and scaffold fresh — never resume with guessed answers |
| `Docker already has a project named "<app>-<hash>"` | An earlier run in this same directory left containers/volumes | Expected after deleting an app and re-scaffolding at the same path — it removes them first. The name carries a hash of the full path, so it never matches another app |
| `Sail could not start: something else on this machine is holding port N (KEY)` | Another program took the port after it was chosen | Stop that program, or set a free port for KEY in `<app>/.env`, then `--continue --defaults` |
| `Directory <path> was created by another run while this one was waiting` | Two terminals scaffolded the same name | Use `--continue` if that run failed; otherwise pick another name |

The address-pool row is the one that hits an agent scaffolding many apps in sequence.

## Anti-Patterns (Never Do)

| Bad | Instead |
|-----|---------|
| `kalimera new` without a name in an agent shell | Positional name + `--defaults`, always |
| Running inside the kalimera repo or an app dir | `cd` to the intended parent first |
| Pointing kalimera at an existing project | Kalimera scaffolds NEW apps; `--continue` only resumes its own failed runs |
| Inventing `--no-interaction`, env vars, or per-prompt flags | Config JSON via `--config=` |
| Bypassing AgentGuard to run `migrate:fresh` | `sail artisan migrate`; destructive resets are the human's call |
| Leaving `kalimera.config.json` in a shared parent dir | Explicit `--config=` to a deliberate path |

## Checklist

```
□ kalimera on PATH (command -v kalimera) — install from the path repo if missing
□ Docker daemon answering (docker info)
□ Shell is in the intended PARENT directory
□ Preferences collected — or the user explicitly said "defaults"
□ Custom setup: config JSON written to a scratch path, rehearsed with --dry-run
□ Command has positional name + --defaults (+ --log)
□ Timeout raised to 10+ minutes, or run in background
□ Verified: exit 0, .kalimera.json gone, sail ps up
□ On failure: read kalimera.log → fix cause → kalimera new <name> --continue --defaults
```

## Quick Reference

```bash
kalimera new my-app --defaults --log                       # default stack, non-interactive
kalimera new my-app --defaults --config=/tmp/k.json --log  # custom stack
kalimera new my-app --dry-run --defaults                   # rehearse: full plan, no changes
cd ~/www && kalimera new my-app --continue --defaults      # resume a failed run
cd my-app && ./vendor/bin/sail up -d                       # start the app later
./vendor/bin/sail composer quality                         # strict quality check
```

For full examples, see `references/examples.md`.
