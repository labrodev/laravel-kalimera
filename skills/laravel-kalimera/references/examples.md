# Kalimera Examples

## Full config — the built-in setup with the catalog preselected

Every `preselected` key at its built-in value, plus the built-in Spatie catalog re-declared with `"preselected": true` on each entry. This is the copy-paste base for "the default stack plus all the Spatie packages" — trim what the user does not want. `label` is only shown in interactive prompts and may be omitted.

```json
{
    "$schema": "https://raw.githubusercontent.com/labrodev/laravel-kalimera/main/schema/kalimera.config.schema.json",
    "preselected": {
        "starterKit": "react",
        "installInertia": false,
        "aroundPackages": ["horizon", "fortify", "ai", "scout", "nightwatch"],
        "sailServices": ["pgsql", "redis"],
        "phpConstraint": "^8.5",
        "qualityTools": ["pint", "phpstan", "rector", "vet"],
        "installPostmark": false,
        "installBoost": true,
        "coreNamespace": "Core",
        "boostAgents": ["claude_code", "cursor", "codex"],
        "boostSkillRepos": [],
        "extraPackages": [],
        "extraDevPackages": []
    },
    "additionalPackages": [
        {
            "package": "spatie/laravel-data",
            "label": "laravel-data — DTOs & validation (replaces Request classes)",
            "preselected": true,
            "publishProviders": ["Spatie\\LaravelData\\LaravelDataServiceProvider"]
        },
        {
            "package": "spatie/laravel-view-models",
            "label": "laravel-view-models — view models for templates",
            "preselected": true
        },
        {
            "package": "spatie/laravel-query-builder",
            "label": "laravel-query-builder — API-friendly query building",
            "preselected": true
        },
        {
            "package": "spatie/laravel-backup",
            "label": "laravel-backup — application & database backups",
            "preselected": true,
            "publishProviders": ["Spatie\\Backup\\BackupServiceProvider"]
        },
        {
            "package": "spatie/laravel-permission",
            "label": "laravel-permission — roles & permissions",
            "preselected": true,
            "publishProviders": ["Spatie\\Permission\\PermissionServiceProvider"]
        },
        {
            "package": "spatie/laravel-activitylog",
            "label": "laravel-activitylog — audit log of model changes",
            "preselected": true,
            "publishProviders": ["Spatie\\Activitylog\\ActivitylogServiceProvider"]
        },
        {
            "package": "spatie/laravel-translatable",
            "label": "laravel-translatable — translatable Eloquent attributes",
            "preselected": true,
            "publishProviders": ["Spatie\\Translatable\\TranslatableServiceProvider"]
        }
    ]
}
```

Catalog entry fields: `package` (required, version constraint like `vendor/pkg:^2.0` allowed), `label`, `dev` (require with `--dev`), `preselected` (chosen by default — installed by `--defaults`), `publishProviders` (each gets `artisan vendor:publish --provider=... --no-interaction`). Entries outside the Spatie set work the same way — the catalog is fully replaceable.

## Pipeline steps

Step numbers shift when conditional steps are skipped — match progress and failures by LABEL, not number.

Everything up to "Guarding destructive commands" runs on the host with no container; composer runs there as the only writer of composer.json. From "Building and starting the Sail containers" on, everything that executes PHP runs in the container.

| Label | Runs when | What it does |
|-------|-----------|--------------|
| Checking requirements | Always, before the numbered steps | `php`, `composer`, `laravel`, `docker`, `git` on PATH; PHP 8.3+ and Composer 2.2+ on the host (warning only under `--dry-run`); `docker info` answers (warning only under `--dry-run`) |
| Creating the Laravel application | Always | `laravel new <name> --pest --git --no-boost --no-interaction [--react\|--vue\|--livewire\|--svelte]`; writes the answers to `.kalimera.json` and gitignores it |
| Installing Laravel Sail | Always | `artisan sail:install --with=<services\|none>` with Docker hidden from it (`DOCKER_HOST` pointed at nothing), so it only writes the compose file and `.env` instead of pulling and building early; `--php=<minor>` pins the runtime (left out, with a warning, when Sail ships no runtime for that version); removes the leftover sqlite db; syncs `DB_*` into `.env.example` |
| Restricting PHP to `<constraint>` | Always | Host edit of composer.json: `require.php` = the constraint, `config.platform.php` = the container's exact PHP from the local Sail image, else the bare minor (so the host's composer resolves for the container's PHP, whatever the host runs; it errs low on purpose). The pin stays in the finished app |
| Setting up static analysis tools | `pint`, `phpstan` or `rector` selected | Published configs (pint.json, phpstan.neon.dist, rector.php), ide-helper gitignore entries, composer scripts. Packages come in "Downloading packages" |
| Preparing the dependency audit | `vet` selected | Allows the `laravel/vet` plugin in composer.json, adds the `vet` script and `@vet` to `quality`. Skipped with a warning when the PHP constraint is below 8.4 |
| Setting up Postmark mail delivery | `installPostmark: true` | `postmark:push` / `postmark:pull` scripts; `POSTMARK_API_KEY` env placeholder |
| Scaffolding the `<Namespace>` src/ structure | `coreNamespace` not null | `src/{Domain,Shared,Support,Feature,Infrastructure}` + PSR-4 map |
| Downloading packages | Always | **Host** `composer require … --no-scripts --no-plugins --ignore-platform-req=ext-*`: one batch for runtime packages (Horizon, Fortify, Laravel AI, Scout, Postmark mailer, Inertia, catalog entries), one `--dev` batch (quality tools, Boost, Vet, dev catalog entries), then Nightwatch and each extra package on its own (warn on failure). Retries only network failures. Packages composer.json already requires are skipped |
| Guarding destructive commands against AI agents | Always | Publishes `AgentGuardServiceProvider`, registers it in `bootstrap/providers.php` |
| Resolving host port conflicts | Always | Probes `APP_PORT`, `VITE_PORT`, `FORWARD_*` and writes the next free ports into `.env`. Runs right before `sail up`, so nothing can take a port in between |
| Building and starting the Sail containers | Always, including on `--continue` | On a fresh run, pins a per-path `COMPOSE_PROJECT_NAME` (`<app>-<hash>`) in `.env`, then removes an inherited compose project first (`down -v`) if containers/volumes already answer to it — only an earlier run in this same directory can — warning with exactly what it removes. Skips when nothing does. Then `sail up -d --wait`; on failure removes leftover containers/network, stops with the port and `.env` key if another program holds one, otherwise retries |
| Installing dependencies in the container | Always | `vet --init` (when vet is selected; warns on failure) to record the finished `vendor/` as the trust baseline, then `sail composer install`: checks the PHP extensions the host skipped, runs the plugins, and runs `package:discover`; then `vendor:publish --tag=laravel-assets --force` |
| Setting up the Laravel ecosystem packages | `horizon`, `fortify`, `ai` or `scout` selected | `horizon:install` (+ providers repair), `fortify:install` (skipped when the kit ships it), Laravel AI `vendor:publish --provider` (config, conversations migration, `make:agent` stubs), Scout config + `SCOUT_DRIVER=database` (`collection` without PostgreSQL/MySQL) in `.env` and `.env.example` |
| Publishing additional package configuration | Any catalog entry selected | `vendor:publish --provider=…` per entry's `publishProviders` |
| Installing Laravel Boost | `installBoost` is not false | Writes `boost.json` with the chosen agents; `boost:install --guidelines --skills --mcp --no-interaction`; `boost:add-skill <repo> --all` per repo (warns on failure); `boost:update --no-discover --no-interaction` (warns on failure) |
| Installing Inertia | `starterKit: none` + `installInertia: true` | `inertia:middleware`; wiring stays manual |
| Finalizing the application | Always | Migrate (retried only while the database is unreachable; leftover schema goes straight to recreating the volume; a migration the server itself refused stops on the spot, since an empty database refuses it identically), `npm install`, `ide-helper`, `rector:fix` twice, `pint:fix`, `phpstan` (auto-baseline), `composer quality`, initial git commit. `.kalimera.json` is deleted after this step, once the whole plan has finished |

## Worked session: custom scaffold

User ask: "New app `crm` with Vue, MySQL + Mailpit, no ecosystem packages, laravel-data and laravel-permission, Postmark."

```bash
cat > /tmp/crm.kalimera.json <<'JSON'
{
    "preselected": {
        "starterKit": "vue",
        "aroundPackages": [],
        "sailServices": ["mysql", "mailpit"],
        "installPostmark": true
    },
    "additionalPackages": [
        {"package": "spatie/laravel-data", "preselected": true, "publishProviders": ["Spatie\\LaravelData\\LaravelDataServiceProvider"]},
        {"package": "spatie/laravel-permission", "preselected": true, "publishProviders": ["Spatie\\Permission\\PermissionServiceProvider"]}
    ]
}
JSON

cd ~/www
kalimera new crm --dry-run --defaults --config=/tmp/crm.kalimera.json   # rehearse
```

The dry run validates the config, prints the summary table, then the full plan (excerpt — real output):

```
 ▶ Step 1/13 — Creating the Laravel application
 → [www] laravel new crm --pest --git --no-boost --no-interaction --vue
 · would save the chosen answers to .kalimera.json so --continue can reuse them
 ...
 ▶ Step 4/13 — Resolving host port conflicts
 · would set APP_PORT=84 in .env (default port is busy)
 · would set FORWARD_DB_PORT=3308 in .env (default port is busy)
 ...
 ▶ Step 7/13 — Downloading packages
 → [crm] composer require spatie/laravel-data spatie/laravel-permission symfony/postmark-mailer --no-scripts --no-plugins --ignore-platform-req=ext-* --no-interaction
 ...
 ▶ Step 11/13 — Publishing additional package configuration
 → [crm] ./vendor/bin/sail artisan vendor:publish --provider=Spatie\LaravelData\LaravelDataServiceProvider --no-interaction
 ...
 ▶ Step 13/13 — Finalizing the application
 → [crm] ./vendor/bin/sail artisan migrate --no-interaction
 → [crm] git commit -m chore: scaffold application with kalimera
```

Plan looks right — run it for real and verify:

```bash
kalimera new crm --defaults --config=/tmp/crm.kalimera.json --log   # 10+ minutes
test ! -f crm/.kalimera.json && echo "completed"
cd crm && ./vendor/bin/sail ps && git log --oneline -1
```

## Worked session: failure and resume

A composer flake skipped `laravel/boost`, so `boost:install` has nothing to run (illustrative output, real formats):

```
 ▶ Step 9/13 — Installing Laravel Boost
 → [crm] ./vendor/bin/sail artisan boost:install --guidelines --skills --mcp --no-interaction
   There are no commands defined in the "boost" namespace.
 Command failed (exit 1): [crm] ./vendor/bin/sail artisan boost:install --guidelines --skills --mcp --no-interaction
 Scaffolding stopped. Fix the issue above and re-run, or continue manually inside the app directory.
```

Diagnose, fix inside the app, resume from the parent:

```bash
tail -50 crm/kalimera.log                 # transcript: what ran, what failed
test -f crm/.kalimera.json && echo "resumable"
php -r 'echo implode(PHP_EOL, json_decode(file_get_contents("crm/.kalimera.json"), true)["completedSteps"]);'  # steps the failed run finished

cd crm && ./vendor/bin/sail composer require laravel/boost --dev
grep '"laravel/boost"' composer.json      # confirm it landed

cd .. && kalimera new crm --continue --defaults
```

The resume walks the same plan and skips what is checkpointed, so the first steps cost nothing:

```
 ▶ Step 1/13 — Creating the Laravel application (done by the previous run — skipping)
 ...
 ▶ Step 5/13 — Building and starting the Sail containers
 → [crm] ./vendor/bin/sail up -d --wait
 ...
 ▶ Step 9/13 — Installing Laravel Boost
```

Starting the containers runs again on purpose — the checkpoint says they were started once, not that they are up now.

When the fix is a changed ANSWER rather than a repaired environment, both halves of `.kalimera.json` matter — `answers` and `completedSteps`. Dropping a package that will not resolve:

```bash
# 1. Remove it from the saved answers — a resume ignores --config= entirely
php -r '$p="crm/.kalimera.json"; $s=json_decode(file_get_contents($p),true);
        $s["answers"]["additionalPackages"]=[]; file_put_contents($p,json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));'

# 2. If its step already completed, remove it from completedSteps too — otherwise
#    the step is skipped and the edit never runs
php -r '$p="crm/.kalimera.json"; $s=json_decode(file_get_contents($p),true);
        $s["completedSteps"]=array_values(array_diff($s["completedSteps"],["AdditionalPackagesInstall"]));
        file_put_contents($p,json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));'

cd ~/www && kalimera new crm --continue --defaults
```

A step that re-runs republishes its template. An edited `pint.json` / `phpstan.neon.dist` / `rector.php` is kept as `<file>.bak` with a warning — nothing is lost, but the live file is the template again.

Port collision between two kalimera apps scaffolded at different times (the probe only sees ports that are busy at scaffold time):

```bash
docker ps --format '{{.Names}}\t{{.Ports}}'   # who holds which host port
# then edit the .env of the app you are NOT running:
# APP_PORT, VITE_PORT, FORWARD_DB_PORT, FORWARD_REDIS_PORT, FORWARD_MAILPIT_PORT, ...
```
