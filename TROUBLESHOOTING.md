# Troubleshooting

Problems that have come up while scaffolding real apps with kalimera — what causes each one and
how to fix it. Mirrored at `/troubleshooting` on kalimera-site.

## Sail fails with "all predefined address pools have been fully subnetted"

```
failed to create network <app>_sail: Error response from daemon: all predefined address pools have been fully subnetted
```

**When:** Running `sail up -d --wait` while scaffolding a new app — the "Building and starting
the Sail containers" step.

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

If the application directory is not there at all — you deleted it to start over, or the first run
died before it was created — `--continue` has nothing to resume and runs as an ordinary fresh
scaffold. That is deliberate: a resume promises to leave the previous run's containers and volumes
alone, which would be exactly the wrong thing to do while creating a new application under a name
Docker still has a project for.

The same file lists, under `completedSteps`, the steps the failed run finished, and a resume skips
them — so it starts at the step that broke instead of replaying the whole plan. Starting the Sail
containers is the one exception and always runs: a checkpoint records that they were started once,
not that they are up now. To force a completed step to run again, delete its entry from
`completedSteps` (or empty the list to replay everything).

The two halves of the file work together, and that matters when the fix is to change an answer.
Editing `answers` — dropping a package that will not resolve, adding a quality tool you decided you
want — only reaches the steps still left to run. A step already listed in `completedSteps` is
skipped no matter what its answers now say, so delete its entry there too when the edit belongs to
work that already happened.

A run that failed on an earlier kalimera release left its answers flat in `.kalimera.json` and its
steps in a separate `.kalimera-steps.json`. Both are still read, and folded into the one file on the
first write.

## `Cannot resume <path>: .kalimera.json is there but the answers it should hold are missing or unreadable`

**When:** `--continue` into an application whose `.kalimera.json` was deleted, hand-edited into
invalid JSON, or lost its `answers` — or one an earlier release left with only `.kalimera-steps.json`
behind.

**Why it happens:** The steps already recorded ran under the saved answers. Rebuilding the plan
from fresh ones — prompts, or whatever `--defaults` says today — would still skip those steps and
finish an application that is half one configuration and half the other, with nothing to say so.
So kalimera refuses instead of guessing.

**Fix:** Restore the `answers` object (from the transcript's argv and your config, or from a copy),
or delete the application directory and scaffold fresh.

## A resumed run replaced a config you edited, leaving a `.bak` file behind

```
WARN phpstan.neon.dist already existed and differed from the template — the original was kept as phpstan.neon.dist.bak.
```

**When:** Resuming with `kalimera new <app-name> --continue` after editing one of the files
kalimera publishes (`pint.json`, `phpstan.neon.dist`, `rector.php`, the AgentGuard provider)
while working out why the first run failed. A `phpstan.neon` you wrote between runs is moved
aside the same way — it would otherwise shadow the published `phpstan.neon.dist` entirely, so
it cannot stay, but it is not worth deleting either.

**Why it happens:** Publishing a template is a copy, so whatever the destination already holds
is overwritten. On a fresh scaffold that is nothing — none of the shipped templates collide with
a file `laravel new` leaves behind. It only bites when a step re-runs under `--continue` over a
file you touched in between, which is exactly when a config gets hand-edited. Rather than lose
the edit silently, kalimera copies the existing file aside first and says so. A run that fails
twice over the same file gets `.bak2`, `.bak3`, and so on, so a later backup never overwrites an
earlier one.

**Fix:** Nothing is lost — your version is the `.bak` file. Merge whatever you meant to keep back
into the published file and delete the backup. To stop the step republishing at all on the next
resume, add it to `completedSteps` in `.kalimera.json` (see the entry above); to keep your file untouched
instead, that is the only way, since a step that runs will publish its template.

The one case where publishing is skipped is a backup that could not be written — a read-only
directory, a full disk. The warning then reads "it was left as it is, and the template was not
published over it": overwriting anyway would destroy the edit the backup exists to protect, and a
template that failed to land is the more recoverable of the two — copy it in from
`templates/` in the kalimera repo once the write problem is fixed.

## "Installing Laravel Boost" fails with "There are no commands defined in the boost namespace"

```
ERROR There are no commands defined in the "boost" namespace.
```

Thrown by `sail artisan boost:install --guidelines --skills --mcp --no-interaction`.

**When:** The "Installing Laravel Boost" step, immediately after
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

## Migrations fail with "duplicate key value violates unique constraint pg_class_relname_nsp_index"

```
SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "pg_class_relname_nsp_index"
DETAIL:  Key (relname, relnamespace)=(migrations_id_seq, 2200) already exists.
(SQL: create table "migrations" ("id" serial not null primary key, ...))
```

**When:** Scaffolding a new app into a directory an earlier, unfinished run used — or, on
applications scaffolded before kalimera pinned `COMPOSE_PROJECT_NAME`, into any directory whose
*name* an earlier run used. The MySQL wording is `SQLSTATE[42S01] ... Table 'migrations' already exists`.

**Why it happens:** Compose files volumes under the project name as `<project>_sail-pgsql`, and
left to itself it takes that name from the directory. A new scaffold under a project name used
before therefore mounts the *old* database. That volume can hold `migrations_id_seq` without the `migrations` table — Laravel's
`hasTable('migrations')` returns false, issues `create table migrations (id serial ...)`, and the
implicit sequence collides with the orphan.

**Fix:** kalimera now handles this itself, in three places. A fresh scaffold pins
`COMPOSE_PROJECT_NAME=<app>-<hash of the full path>` in `.env`, so `~/work/api` and `~/side/api`
are two projects to docker and can never share a database. Before the first `up`, `SailStart`
asks docker whether anything already answers to that name — the `<project>-<service>-1`
containers and the `<project>_sail-<service>` volumes — which only an earlier run in this very
directory can have left, and runs `sail down -v` only if something does, naming what it is about
to remove. Nothing found means nothing touched, which is the usual case; a `--continue` run keeps
its data and is never asked. A resumed run whose `.env` predates the key keeps the directory-name
project its containers already use. If a conflict still gets through, `AppFinalize`
recreates the volume immediately rather than retrying three times first: `MigrationFailure` retries
only when the database could not be *reached* (SQL class 08, MySQL 2002/2003/2006, or no output at
all). Once the server has answered, its verdict will be the same on every attempt.

To clear it by hand:

```bash
./vendor/bin/sail down -v && ./vendor/bin/sail up -d --wait && ./vendor/bin/sail artisan migrate
```

## `Sail could not start: something else on this machine is holding port N (KEY)`

**When:** Starting the containers, usually on a `--continue` run some time after the first one.

**Why it happens:** Host ports are chosen once, when `SailPortsConfigure` runs, and written to
`.env`. That step is checkpointed, so a resume does not choose again — and another program (often
a second kalimera application) may have taken the port since. `SailStart` retries once after
clearing its own leftover containers; if the port is still taken after that, the holder is not
this application, and a third `up` would fail the same way under an error about containers.

**Fix:** Stop whatever holds the port, or set a free one for that key in the application's `.env`,
then resume with `--continue --defaults`.

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

## Every composer command in a scaffolded app fails with "packages are not trusted"

```
   ERROR  [3] packages are not trusted. Run [./vendor/bin/vet] in a terminal to pick the ones
          that you trust.
```

**When:** Any `sail composer install` / `update` / `require` in an application scaffolded with
`vet` among its quality tools — most often right after adding a package.

**Why it happens:** This is Vet working, not a failure. `laravel/vet` runs as a composer plugin
and gates every install on `vet.json`: a version the file does not record is shown as a diff and
refused, so a package nobody has read cannot reach `vendor/` unnoticed. Composer writes the new
`composer.lock` before the plugin stops it, so lock and `vendor/` can disagree afterwards — and
Vet then refuses to record anything until they agree again.

**Fix:** Run `sail composer vet` **in a terminal**. The plugin never asks — it can only report —
so a script, a CI job or an agent driving the app non-interactively will always exit 1 here. The
interactive run lists the packages, will hand each diff to a coding agent if you ask it to, and
records the ones you accept. If it refuses with "composer would write N packages that vendor/
does not hold", run `sail composer install` first so the two agree, then vet again.

Do not delete `vet.json` to get past this: that discards every recorded version, and the next
`vet --init` re-trusts whatever is on disk unread — the one thing the file exists to prevent.

## Turning on Vet's `minimum-release-age` after scaffolding

**When:** You want the supply-chain delay — every release held back until it is N days old, so a
compromised version has time to be found and pulled. Kalimera does not set it, because the floor
rejects a release younger than it *even when the trust file records it*: a freshly scaffolded
application would arrive failing its own audit, and nothing unattended can clear that.

**Why it needs a recipe:** `vet --init --minimum-release-age=7` sets the floor and immediately
fails on the young releases already installed. `composer update` answers that — re-resolving
against the floor rewrites `composer.lock` onto releases old enough to pass — but Vet stops that
same command before it writes them into `vendor/`, because they are versions nobody has read. The
lock and `vendor/` now disagree, and `vet --fresh` refuses to record while they do. Run the four
commands below in order and the project lands green; stop halfway and every composer command in
it exits 1 until you review the difference in a terminal.

```bash
./vendor/bin/sail php vendor/bin/vet --init --minimum-release-age=7   # exits 1 — expected
./vendor/bin/sail composer update                                     # exits 1 — expected, moves the lock
./vendor/bin/sail composer install --no-plugins                       # syncs vendor/ to that lock
./vendor/bin/sail php vendor/bin/vet --fresh                          # records it; keeps the floor
```

The third command is the one that matters: `--no-plugins` installs the lock Vet just produced
with Vet's own gate off for that single command. It widens nothing — a baseline is recorded
unread either way, `--init` included — it only lets the tree reach the versions the floor asked
for before `--fresh` reads it. Afterwards `composer install`, `composer update` and
`composer vet` all exit 0, and the floor applies to everything from there on.

Expect the lock to move backwards by up to N days, `laravel/framework` included. Exempt packages
with `minimum-release-age-exclude` in `vet.json` (`*` matches any part of a name, so `laravel/*`
covers the first-party packages); Vet always exempts itself. Removing `minimum-release-age`
switches the wait off again — the recorded versions stay trusted.
