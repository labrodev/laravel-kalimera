# Changelog

All notable changes to `labrodev/kalimera` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- [Laravel Vet](https://github.com/laravel/vet) as a default quality tool: `vet` joins
  `qualityTools` alongside pint, phpstan and rector, and is preselected. Its step is the last
  one in the plan that touches composer, because the `vet.json` it records becomes the
  application's trust baseline and has to describe the vendor directory the whole scaffold
  produced — and because its composer plugin fails any install of a package the trust file does
  not already cover, which every package step after it would be. The plugin is allowed in
  composer.json *before* the `require`, since composer aborts on an unlisted composer-plugin
  rather than warning past it, leaving the package in the manifest and out of `vendor/`. Adds a
  `vet` composer script and `@vet` to `quality`, so an unreviewed dependency change fails the
  gate. No `minimum-release-age` is recorded: a floor rejects releases younger than it even when
  they are trusted, so setting one would hand over an application failing its own audit with no
  way to clear it unattended. The step skips itself with a warning when the application's PHP
  constraint is below vet's own 8.4 requirement, rather than failing the scaffold two steps from
  the end.
- `--verbose` / `-v`: command output is condensed to a single progress line by default —
  the Sail image build alone is tens of thousands of apt lines — and this streams it raw
  instead. A failed command replays its last 40 lines either way, and `--log` captures
  everything regardless of the flag.
- Step checkpointing for `--continue`: every finished step is recorded in `.kalimera.json`
  under `completedSteps`, beside the saved `answers`, so a resume starts at the step that broke
  instead of replaying the whole plan. The file is versioned, gitignored, and removed once —
  by the orchestrator, after the whole plan has finished. A flat answers file and a
  `.kalimera-steps.json` left by an earlier release are still read. Starting the Sail containers is exempt and always re-runs: the
  checkpoint records that they were started once, not that they are up now.
- Published templates no longer overwrite a hand-edited file. When the destination differs
  from the template — which happens when a step re-runs under `--continue` over a config
  edited while diagnosing the failure — the original is kept beside it as `.bak` (`.bak2`,
  `.bak3`, … for repeats) and the substitution is announced. A backup that cannot be
  written cancels the publish rather than destroying the edit.
- Sail start pins a per-path compose project name and discards an inherited project before
  the first `up`. A fresh scaffold writes `COMPOSE_PROJECT_NAME=<app>-<hash of the full path>`
  to `.env`, so two applications with the same directory name are separate projects to docker.
  The containers and volumes are asked for by that name and `down -v` runs only if something
  answers — which only an earlier run in the same directory can — naming exactly what it is
  about to remove; a `--continue` run keeps its data and is never asked.
- `InstallPlan`, the step list on its own, with its ordering rules asserted by name
  (`InstallPlanTest`): containers up before anything runs through sail, the PHP constraint
  before any package resolves, vet after every other dependency change, finalize last. Rules
  about what a step does are checked against the commands it actually issues, so a new step
  running `composer require` is held to the vet rule without being added to a list.
- A rerun test (`StepRerunTest`) that runs every step a second time, as `--continue` would
  after it failed halfway, and asserts the application on disk does not change.
- Migration failures are classified before being retried (`MigrationFailure`). Waiting only
  helps when the database could not be *reached* — SQL class 08, MySQL 2002/2003/2006, or
  no output at all. A server that has answered gives the same verdict every time, so
  leftover schema goes straight to recreating the volume instead of replaying the same
  duplicate-relation error three times.
- Commands killed by a timeout or a signal now fail as `CommandFailedException::aborted`
  with the output tail attached, so the retry budget and every recovery path treat them
  like any other failure instead of letting them escape past both.
- `ProcessRunner::probe()` for questions about the environment — is Docker answering, does
  this file parse. It changes nothing, so a dry run asks it too and can report what a real
  run would have removed.
- A nightly workflow (`.github/workflows/nightly-scaffold.yml`) that scaffolds a real
  application end to end and asserts the containers are up and the migrations applied,
  plus a second job that breaks a run on purpose and resumes it. Every bug found in real
  use so far survived the faked-runner suite and only showed up in an actual run.
- CI additionally runs on macOS (the platform whose VirtioFS and TTY quirks the process
  handling works around), against the lowest resolvable dependencies, and with coverage
  reporting (`composer test:coverage`, informational rather than a gate).

### Changed

- The transcript keeps its file open for the whole run instead of opening, locking and
  closing it for every chunk of child output — about 17× faster over a Sail build's worth of
  output (50k chunks: 1.4 s → 84 ms). Writes stay unbuffered, so a killed run keeps its last
  lines.
- Application paths are canonical: `app`, `./app` and the same directory reached through a
  symlink resolve to one path, so the run lock and everything else keyed on it see one
  application.
- `ProcessRunner::runCommandQuietly()` is split into `probe()` and `attemptQuietly()`.
  Both are silent and neither throws, but a probe only asks while an attempt changes the
  environment — so a dry run skips the attempt (and prints it, since the quiet commands
  are the destructive ones a reader most needs to see coming) and still runs the probe.
- The retry budget for commands that reach the network is one shared constant,
  `ProcessRunner::NETWORK_ATTEMPTS`. Migrations keep their own, separate budget: waiting
  out a database that has not finished booting is a different problem from a flaky
  download.

- An `installBoost` preselected key (default `true`, so the existing behavior is unchanged).
  Boost was the only heavy step with no way to decline it, which left anyone scaffolding
  without AI agents paying for a `composer require` and a `boost:install` they did not want,
  and left the nightly resume job no lean plan to exercise the checkpoint machinery against.
  Declining it also retires the two questions behind it — which agents, which skill
  repositories — rather than collecting answers for a step that will not run.

### Fixed

- Scaffolding no longer wipes the database of another application that shares the directory
  name. The compose project used to be the bare directory name, so `SailStart`'s cleanup of an
  "inherited" project ran `down -v` against an unrelated live application — without asking,
  including under `--defaults`.
- A fresh run that waited at a prompt while another run created and finished the same
  directory now stops after taking the lock, instead of scaffolding over that application as
  if it were new and clearing its containers.
- `--continue` refuses to resume when state exists but its answers are missing or unreadable,
  instead of rebuilding the plan from fresh answers while still skipping the steps that ran
  under the old ones. `AppFinalize` no longer deletes the answers mid-step, which left exactly
  that state behind when a run was interrupted between the two deletes.
- The composer.json snapshot the manifest guard restores from lives in the run state and goes
  with it. It used to sit in the system temp directory forever, where it could be restored
  into the next application scaffolded at the same path.
- A port taken by another program after it was chosen is named in the error — port and `.env`
  key — instead of surfacing as a second "Sail could not start" about leftover containers.
- The manifest guard's warning names the full path of the damaged `composer.json` it kept,
  not just its file name.

- A migration the database itself refused no longer destroys the database. Only a schema
  conflict or a server that never answered leads to `down -v`; a migration that will not
  parse, or a constraint the schema cannot satisfy, is refused identically by an empty
  database, so recreating it bought nothing and buried the real error under a second copy
  of itself. That failure now stops on the spot with its original message.
- A resumed run no longer skips a Fortify installation that never happened. Whether Fortify
  was already present was decided by looking for `laravel/fortify` in composer.json, which
  cannot tell a starter kit that bundles it apart from kalimera's own `composer require` on
  the line above — so a run that required the package and then failed on `fortify:install`
  replayed as "already shipped by the starter kit", skipped the install, and exited 0 over an
  application with the requirement but no config, no actions and no registered provider. The
  published config is what gets checked now.
- `--continue` pointed at a directory that is not there is treated as the fresh scaffold it
  actually is. The flag outlives a deleted application directory, and carrying it through
  told `SailStart` to preserve an earlier project's containers and volumes while the run
  scaffolded a brand-new application into their path — inheriting a half-migrated database
  from the very run it was replacing.
- A command that never starts — a working directory that is not there — now fails as
  `CommandFailedException` like a timeout or a signal, instead of escaping as a raw Symfony
  exception past the retry budget and every `catch (CommandFailedException)` recovery path.
- The skeleton `phpstan.neon` is moved aside rather than deleted when a resumed run
  republishes the quality configs, since by then it may be one the user wrote while
  diagnosing the failure. A fresh scaffold still deletes it outright — there it is
  `laravel new`'s own file, and a `.bak` beside it would be litter in every new application.
- Both workflows declare a least-privilege `permissions:` block and pin every third-party
  action to a commit SHA.

## [1.0.0] - 2026-08-13

### Added

- Interactive `kalimera new` command that scaffolds a Laravel application in one guided flow.
- Starter kit selection (React / Vue / Livewire / Svelte / none, with optional manual Inertia).
- Laravel ecosystem package selection: Horizon, Fortify, Laravel AI, Nightwatch (Fortify is
  skipped automatically when the starter kit already ships it).
- Sail installation with service selection, PHP runtime pinning, and automatic host port
  conflict resolution.
- PHP version constraint prompt (default `^8.5`) applied to the generated composer.json.
- Static analysis setup: Pint, PHPStan (Larastan + IDE Helper), Rector with ready-made
  configurations and composer scripts (`pint:dry`, `pint:fix`, `phpstan`, `phpstan-clear`,
  `ide-helper`, `rector:dry`, `rector:fix`, `quality`).
- Additional package selection from a configurable catalog. The built-in catalog is the
  Spatie collection: laravel-data, laravel-view-models, laravel-query-builder,
  laravel-backup, laravel-permission, laravel-activitylog, laravel-translatable.
- Optional `kalimera.config.json` (auto-discovered in the working directory, or passed via
  `--config=path`) that overrides the preselected answers — what `--defaults` installs — and
  replaces the additional-packages catalog with your own (package, label, dev flag,
  preselected flag, providers to vendor:publish). The built-in setup lives in the
  packaged `schema/kalimera.config.schema.json` as its default values — the installer
  reads it on start, so editing that file changes the out-of-the-box offering; validation
  lives in the installer itself, with precise errors before anything is installed.
- Laravel Boost installation with upfront AI agent selection (preconfigured `boost.json`)
  and skills import from one or more GitHub repositories via `boost:add-skill` (the prompt
  accepts a space- or comma-separated list). A repository that cannot be added is reported
  and skipped instead of aborting the installation.
- Optional Postmark SDK setup (`wildbit/postmark-php`) with `postmark:push` / `postmark:pull`
  composer scripts.
- Optional Core structure scaffold (`src/{Domain,Shared,Support,Feature,Infrastructure}`)
  with a configurable PSR-4 namespace and `.gitkeep` files keeping the layers committable.
- Finalization pass: migrations, npm install, ide-helper generation, Rector and Pint
  formatting, PHPStan baseline, and an initial git commit — `composer quality` is green
  on a fresh scaffold.
- `--dry-run`, `--defaults` and `--continue` flags.
- `--log[=path]` flag that writes an append-only transcript of steps, commands and
  outcomes to a log file. The default destination is `kalimera.log` inside the created
  application, added to its `.gitignore`; lines recorded before `laravel new` are buffered
  and flushed once the directory exists, falling back to the working directory when the
  application was never created (dry runs, early failures).
- Atomic file writes so containerized processes never observe half-written files.
- Dedicated exception classes for every failure mode (failed commands, missing
  requirements, invalid targets, failed file writes, missing templates, providers
  repair failures, unreadable composer.json).
- Package test suite (Pest 4) with Unit, Feature and Arch suites: unit coverage for
  every installer class, an in-process dry-run snapshot of the full command sequence,
  and architecture rules (strict types, readonly classes, layer boundaries).
- Package tooling: Pint, Rector and PHPStan (level 8) configurations, a
  `composer quality` aggregate, and a GitHub Actions CI workflow on PHP 8.4 / 8.5.
- Automatic retry with a short delay for in-container package installation commands
  (composer require, npm install), absorbing transient Docker filesystem hiccups.
- `--continue` reuses the answers saved in the app's `.kalimera.json` state file instead of
  prompting again (written after `laravel new`, removed when the scaffold completes).
- A rising-sun ASCII banner greets interactive runs (skipped when output is piped).
- Sail start self-heals container-name conflicts: when `sail up` fails, leftover containers
  and the project network from an interrupted earlier run are removed and the start retried.
- A per-application run lock: a second kalimera run on the same target directory is refused
  with a clear error instead of silently corrupting composer.json and vendor/.
- Failed commands are guaranteed dead before a retry starts, so a TTY-mode wait interrupted
  by a signal can no longer leave the first attempt racing its own retry.
- Commands run through streamed pipes instead of TTY passthrough: TTY-mode exit codes are
  unreliable on macOS and caused successful commands to be retried and race themselves;
  the `--log` transcript now captures full command output as a bonus.
- composer.json is guarded across every composer command: when a failed install makes
  composer restore an empty manifest (losing autoload and scripts), the snapshot taken
  before the command is merged back with the new requirements, vendor/ is reinstalled from
  the lock file, and only then is the command retried — a retry against a gutted manifest
  used to "succeed" and leave the application unbootable.
- The container home directory is handed to the `sail` user after start, so composer can
  write its cache instead of re-downloading every package on every command.
- Failed composer commands recover by clearing the cache (a truncated archive is cached and
  replayed by every later attempt) and reinstalling from the lock file, and every retry
  downloads one package at a time instead of in parallel.
- Migrations self-heal against a dirty database: when an interrupted scaffold has left
  schema behind (orphaned sequences survive `db:wipe`), the database volume is recreated
  and the migration retried, because the application has never run at that point.
- The PHP constraint is applied by composer inside the container instead of a host-side
  file write, and host file changes settle for a second before the next container command —
  a container reading a freshly host-renamed file through VirtioFS can briefly see it empty,
  which composer silently treats as a new project, discarding composer.json.

- An agent skill (`skills/laravel-kalimera/`) that teaches AI coding agents to drive kalimera:
  collect stack preferences, run non-interactively with `--defaults` and a config JSON, verify
  success, and resume failed runs. Plain Markdown in the open Agent Skills format — symlink into
  `~/.claude/skills/` for Claude Code, or feed it to any skills-aware tool.
- Dry runs skip the in-container home-directory fixup: it execs into a container that a dry run
  never starts, which crashed fresh-target rehearsals (nonexistent working directory) and would
  have touched a live container on a `--continue` rehearsal.

### Changed

- Raised the package PHP requirement to `^8.4`.
- Removed the `final` keyword from all package classes so consumers can extend them
  (generated applications still receive the `final_class` Pint rule).
- Payload constructors order required parameters before nullable ones.
- Reorganized to the Labrodev playbook structure: Contracts, Exceptions, Payloads, Pipelines,
  Services; single-entry services are invokable.
- Renamed services to the `{Noun}{VerbAgent}` convention: `ComposerFileEditor`,
  `EnvFileWriter`, `SailCommandBuilder`.
- Declared the package as a composer `library` (was `project`).
- The config vocabulary separates what is there from what is chosen: the `defaults` block is
  renamed to `preselected` (a legacy `defaults` key is refused with a pointer to the new name),
  the catalog flag `default` is renamed to `preselected`, and the packaged schema now declares
  every option list as a JSON-Schema enum — editors autocomplete the available choices, and the
  schema documents options and preselection as two distinct things.
- The ecosystem-packages prompt ships with the full set — Horizon, Fortify, Laravel AI,
  Nightwatch — all preselected: everything kalimera offers is on the table by default, and
  answering the prompt (or trimming `aroundPackages` in `kalimera.config.json`) is how you
  opt out, not in. Fortify still auto-skips when the starter kit already ships it.
- Default Sail services are now PostgreSQL + Redis (MySQL and Mailpit remain selectable),
  and selecting none is allowed — Sail then installs the application container only and the
  application keeps its sqlite database.
