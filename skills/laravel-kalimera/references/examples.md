# Kalimera Examples

## Full config — the built-in setup with the catalog preselected

Every `preselected` key at its built-in value, plus the built-in Spatie catalog re-declared with `"preselected": true` on each entry. This is the copy-paste base for "the default stack plus all the Spatie packages" — trim what the user does not want. `label` is only shown in interactive prompts and may be omitted.

```json
{
    "$schema": "https://raw.githubusercontent.com/labrodev/laravel-kalimera/main/schema/kalimera.config.schema.json",
    "preselected": {
        "starterKit": "react",
        "installInertia": false,
        "aroundPackages": ["horizon", "fortify", "ai", "nightwatch"],
        "sailServices": ["pgsql", "redis"],
        "phpConstraint": "^8.5",
        "qualityTools": ["pint", "phpstan", "rector"],
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

| Label | Runs when | What it does |
|-------|-----------|--------------|
| Checking requirements | Always, before the numbered steps | `php`, `composer`, `laravel`, `docker`, `git` on PATH; `docker info` answers (warning only under `--dry-run`) |
| Creating the Laravel application | Always | `laravel new <name> --pest --git --no-boost --no-interaction [--react\|--vue\|--livewire\|--svelte]`; writes `.kalimera.json` and gitignores it alongside `.kalimera-steps.json` |
| Installing Laravel Sail | Always | `artisan sail:install --with=<services\|none>`; removes the leftover sqlite db; syncs `DB_*` into `.env.example` |
| Matching the Sail runtime to PHP X.Y | Always | Pins the compose file to the chosen PHP minor |
| Resolving host port conflicts | Always | Probes `APP_PORT`, `VITE_PORT`, `FORWARD_*` and writes the next free ports into `.env` |
| Building and starting the Sail containers | Always, including on `--continue` | On a fresh run, removes an inherited compose project first (`down -v`) if containers/volumes already answer to this directory's name — warns with exactly what it removes, and skips when nothing does. Then `sail up -d --wait`; on failure removes leftover containers/network and retries |
| Restricting PHP to `<constraint>` | Always | `sail composer require php:<constraint> --no-update` |
| Installing Laravel ecosystem packages | `aroundPackages` not empty | Horizon (+ `horizon:install`), Fortify (skipped when the kit ships it), Laravel AI, Nightwatch (last two warn on failure) |
| Setting up static analysis tools | `qualityTools` not empty | Pint, Larastan, IDE Helper, Rector + published configs, empty PHPStan baseline, composer scripts |
| Installing additional packages | Any catalog entry selected | Batched `composer require` (+ `--dev` batch), then `vendor:publish` per entry |
| Installing Laravel Boost | `installBoost` is not false | Writes `boost.json` with the chosen agents; `boost:install --guidelines --skills --mcp --no-interaction`; `boost:add-skill <repo> --all` per repo (warns on failure); `boost:update --no-discover --no-interaction` to refresh guidelines and skills (warns on failure) |
| Setting up the Postmark SDK | `installPostmark: true` | `wildbit/postmark-php`; `postmark:push` / `postmark:pull` scripts; `POSTMARK_API_KEY` env placeholder |
| Installing Inertia | `starterKit: none` + `installInertia: true` | `inertiajs/inertia-laravel` + middleware; wiring stays manual |
| Scaffolding the `<Namespace>` src/ structure | `coreNamespace` not null | `src/{Domain,Shared,Support,Feature,Infrastructure}` + PSR-4 map + dump-autoload |
| Installing extra packages | `extraPackages` / `extraDevPackages` set | One `composer require` per package (warns on failure) |
| Guarding destructive commands against AI agents | Always | Publishes `AgentGuardServiceProvider`, registers it last in `bootstrap/providers.php` |
| Finalizing the application | Always | Migrate (retried only while the database is unreachable; leftover schema goes straight to recreating the volume; a migration the server itself refused stops on the spot, since an empty database refuses it identically), `npm install`, `ide-helper`, `rector:fix` twice, `pint:fix`, `phpstan` (auto-baseline), `composer quality`, delete `.kalimera.json` and `.kalimera-steps.json`, initial git commit |

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
 ▶ Step 8/13 — Installing additional packages
 → [crm] ./vendor/bin/sail composer require spatie/laravel-data spatie/laravel-permission
 → [crm] ./vendor/bin/sail artisan vendor:publish --provider=Spatie\LaravelData\LaravelDataServiceProvider --no-interaction
 ...
 ▶ Step 13/13 — Finalizing the application
 → [crm] ./vendor/bin/sail artisan migrate --no-interaction
 → [crm] git commit -m chore: scaffold application with kalimera
```

Plan looks right — run it for real and verify:

```bash
kalimera new crm --defaults --config=/tmp/crm.kalimera.json --log   # 10+ minutes
test ! -f crm/.kalimera.json && test ! -f crm/.kalimera-steps.json && echo "completed"
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
cat crm/.kalimera-steps.json              # the steps the failed run finished

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

When the fix is a changed ANSWER rather than a repaired environment, both files matter. Dropping a package that will not resolve:

```bash
# 1. Remove it from the saved answers — a resume ignores --config= entirely
php -r '$p="crm/.kalimera.json"; $a=json_decode(file_get_contents($p),true);
        $a["additionalPackages"]=[]; file_put_contents($p,json_encode($a,JSON_PRETTY_PRINT));'

# 2. If its step already completed, remove it from the checkpoint too — otherwise
#    the step is skipped and the edit never runs
php -r '$p="crm/.kalimera-steps.json"; $s=json_decode(file_get_contents($p),true);
        file_put_contents($p,json_encode(array_values(array_diff($s,["AdditionalPackagesInstall"])),JSON_PRETTY_PRINT));'

cd ~/www && kalimera new crm --continue --defaults
```

A step that re-runs republishes its template. An edited `pint.json` / `phpstan.neon.dist` / `rector.php` is kept as `<file>.bak` with a warning — nothing is lost, but the live file is the template again.

Port collision between two kalimera apps scaffolded at different times (the probe only sees ports that are busy at scaffold time):

```bash
docker ps --format '{{.Names}}\t{{.Ports}}'   # who holds which host port
# then edit the .env of the app you are NOT running:
# APP_PORT, VITE_PORT, FORWARD_DB_PORT, FORWARD_REDIS_PORT, FORWARD_MAILPIT_PORT, ...
```
