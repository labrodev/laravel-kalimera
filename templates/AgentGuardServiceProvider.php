<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laravel\AgentDetector\AgentDetector;

/**
 * Blocks destructive commands (db:wipe, migrate:fresh/refresh/reset/rollback) while an AI
 * agent is driving the application.
 *
 * MUST stay LAST in bootstrap/providers.php. The starter kits ship an AppServiceProvider
 * that already calls DB::prohibitDestructiveCommands(app()->isProduction()); providers boot
 * in registration order and the last writer wins, so booting this one earlier lets that call
 * silently reset the guard back to false outside production.
 *
 * The detector ships with laravel/pao, which is a require-dev package: a production
 * deploy running `composer install --no-dev` removes it. The class_exists() check keeps
 * that deploy from fatalling on a missing class — production is guarded by isProduction()
 * anyway, so nothing is lost when the detector is absent.
 */
class AgentGuardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction() || $this->isAgent());
    }

    /**
     * Whether an AI agent (Claude Code, Cursor, Codex, Copilot, …) is running this process.
     */
    protected function isAgent(): bool
    {
        if (! class_exists(AgentDetector::class)) {
            return false;
        }

        return AgentDetector::detect()->isAgent;
    }
}
