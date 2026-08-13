# Troubleshooting

Problems that have come up while scaffolding real apps with kalimera — what causes each one and
how to fix it. Mirrored at `/troubleshooting` on kalimera-site.

## Sail fails with "all predefined address pools have been fully subnetted"

```
failed to create network <app>_sail: Error response from daemon: all predefined address pools have been fully subnetted
```

**When:** Running `sail up -d --wait` while scaffolding a new app (kalimera Step 5, "Building and
starting the Sail containers").

**Why it happens:** Every Sail project gets its own dedicated Docker bridge network, and Sail
never removes it once a project is deleted or abandoned. Docker Desktop's default address pool
only holds a limited number of `/20` subnets — roughly 27–31 on a typical install — before every
predefined pool is already claimed by leftover networks, and Docker refuses to create a new one.

**Fix:** Run `docker network ls` to see how many networks exist, then `docker network prune` to
remove the ones with no containers attached (Docker refuses to prune a network still in use, so
this only clears genuinely orphaned networks from finished or abandoned projects). Re-run
`sail up -d --wait` afterwards. If this keeps recurring, raise `default-address-pools` in Docker
Desktop's daemon settings so it allocates from a larger pool.

## `kalimera new --continue` hangs or errors with "Required." in a non-interactive shell

```
Required.
Scaffolding stopped. Fix the issue above and re-run, or continue manually inside the app directory.
```

**When:** Resuming a failed scaffold with `kalimera new --continue` — either without the app name
as a second argument, or while running the command from inside the app directory itself.

**Why it happens:** Without an explicit app-name argument, kalimera falls back to an interactive
prompt asking what the application should be named. In a non-interactive shell (CI, a scripted
run, an agent without a TTY) that prompt has nothing to read and fails immediately. Passing just
`--continue` doesn't skip this — only a name/path positional argument does. And because the
target path is resolved relative to the current working directory, running from inside the app
directory itself resolves to a nested, wrong path even when a name is supplied.

**Fix:** Run it from the parent directory with the app name as the second argument:

```bash
cd ~/www && kalimera new <app-name> --continue --defaults
```

`--continue` reuses the answers saved in the app's `.kalimera.json`, so `--defaults` only matters
for the final "Scaffold the application now?" confirmation — include it anyway so nothing prompts.

## Step 10 fails with "There are no commands defined in the boost namespace"

```
ERROR There are no commands defined in the "boost" namespace.
```

Thrown by `sail artisan boost:install --guidelines --skills --mcp --no-interaction`.

**When:** kalimera Step 10, "Installing Laravel Boost", immediately after
`composer require laravel/boost --dev`.

**Why it happens:** The `composer require laravel/boost --dev` that runs just before this step
has, at least once, reported success ("Nothing to install, update or remove") without actually
adding the package to composer.json or vendor/ — an intermittent Composer resolution flake we
haven't fully root-caused yet. Since the package was never installed, its service provider is
never discovered and none of its artisan commands exist.

**Fix:** Re-run the same command by hand inside the app directory —
`sail composer require laravel/boost --dev` — and confirm `laravel/boost` now appears in
composer.json before continuing. On every occurrence so far, the second attempt has succeeded
immediately. Then resume the scaffold (see the previous entry).

## PHPStan exits with no output at all when a scaffolded path like `src` is deleted later

**When:** Running `composer phpstan` / `composer quality` after removing an empty
`src/{Domain,...}` directory that kalimera scaffolded for the Core structure, once the project
ends up not using it.

**Why it happens:** `QualityToolsInstall::stripSrcPathUnlessScaffolded` only strips the `- src`
path from `phpstan.neon.dist` (and `rector.php`) automatically when the Core structure was never
chosen during scaffolding. If it *was* scaffolded and `src/` is deleted afterwards,
`phpstan.neon.dist` still lists a path that no longer exists on disk, and PHPStan — run through
Sail's docker exec — fails on that with a bare exit code 1 and zero output instead of a clear
error, which reads as a crash rather than a config problem.

**Fix:** Remove the stale `- src` line from `phpstan.neon.dist` (and `__DIR__.'/src'` from
`rector.php` if present) to match the current codebase, or restore an empty `src/.gitkeep` if the
Core structure is still wanted. Confirm with `sail composer phpstan` — it should print real output
(pass, or a list of errors) instead of failing silently. This isn't a bug in kalimera's own
scaffolding logic (a fresh scaffold that skips Core strips the path correctly) — it's a trap for
whoever deletes `src/` by hand after the fact without touching the generated configs.

## A previously working app suddenly fails to start with "port is already allocated"

```
Error response from daemon: driver failed programming external connectivity on endpoint
<app>-redis-1: Bind for 0.0.0.0:6382 failed: port is already allocated
```

**When:** Running `sail up -d` on an app that started fine before, after a different
kalimera-scaffolded project has been started in the meantime.

**Why it happens:** `NetworkPortChecker::isBusy()` picks host ports by opening a socket against
`127.0.0.1` to see what is listening *at that moment*. A port held by a project whose containers
are currently stopped looks completely free, so a later scaffold happily writes the same
`APP_PORT` / `VITE_PORT` / `FORWARD_*_PORT` values into its own `.env`. Nothing detects the clash
until both projects are up at once — then whichever starts second fails. Two apps scaffolded weeks
apart can end up with byte-identical port blocks.

**Fix:** Pick free ports for one of the projects and edit its `.env` (`APP_PORT`, `VITE_PORT`,
`FORWARD_REDIS_PORT`, `FORWARD_DB_PORT`), then `sail up -d`. To see what is actually taken:

```bash
docker ps --format '{{.Names}}\t{{.Ports}}'
```

Choosing new ports for the app you are *not* currently running avoids disturbing a live one. This
touches only `.env`, so it is safe and reversible.

## The AI-agent guard silently does nothing — destructive commands still run

**When:** After wiring laravel/pao's `AgentDetector` into `DB::prohibitDestructiveCommands()` via
a dedicated service provider, when that provider is registered *before* `AppServiceProvider` in
`bootstrap/providers.php`.

**Why it happens:** The React/Vue/Livewire starter kits ship an `AppServiceProvider` that already
calls `DB::prohibitDestructiveCommands(app()->isProduction())`. Providers boot in registration
order and `prohibitDestructiveCommands()` is a plain static setter, so the last call wins. A guard
provider listed *above* `AppServiceProvider` sets the flag to `true`, then `AppServiceProvider`
immediately resets it to `false` in any non-production environment — the guard looks correctly
installed and is completely inert.

**Fix:** Register the guard provider **last** in `bootstrap/providers.php` so it boots after
`AppServiceProvider`, or fold the agent check into the existing `AppServiceProvider` call:

```php
DB::prohibitDestructiveCommands(app()->isProduction() || AgentDetector::detect()->isAgent);
```

Verify for real with `sail artisan migrate:fresh` — it must warn and refuse. `AgentGuardConfigure`
appends the provider at the end of the array for exactly this reason.
