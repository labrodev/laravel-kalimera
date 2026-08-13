# Changelog

All notable changes to `labrodev/kalimera` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
