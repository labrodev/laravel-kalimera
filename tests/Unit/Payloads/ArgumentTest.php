<?php

declare(strict_types=1);

use Kalimera\Payloads\Argument;

it('reads the command and preset name from the positionals', function (): void {
    $argument = Argument::fromArgv(['new', 'demo-app']);

    expect($argument->command)->toBe('new')
        ->and($argument->presetName)->toBe('demo-app')
        ->and($argument->dryRun)->toBeFalse()
        ->and($argument->useDefaults)->toBeFalse()
        ->and($argument->resume)->toBeFalse()
        ->and($argument->logPath)->toBeNull()
        ->and($argument->configPath)->toBeNull();
});

it('leaves the command and preset name empty without positionals', function (): void {
    $argument = Argument::fromArgv([]);

    expect($argument->command)->toBeNull()
        ->and($argument->presetName)->toBeNull();
});

it('parses the dry-run flag', function (): void {
    expect(Argument::fromArgv(['new', '--dry-run'])->dryRun)->toBeTrue();
});

it('parses the defaults flag', function (): void {
    expect(Argument::fromArgv(['new', '--defaults'])->useDefaults)->toBeTrue();
});

it('parses the continue flag as resume', function (): void {
    expect(Argument::fromArgv(['new', '--continue'])->resume)->toBeTrue();
});

it('turns a bare log flag into an empty log path', function (): void {
    expect(Argument::fromArgv(['new', '--log'])->logPath)->toBe('');
});

it('reads an explicit log path from the log flag', function (): void {
    expect(Argument::fromArgv(['new', '--log=/tmp/kalimera.log'])->logPath)->toBe('/tmp/kalimera.log');
});

it('turns a bare config flag into an empty config path', function (): void {
    expect(Argument::fromArgv(['new', '--config'])->configPath)->toBe('');
});

it('reads an explicit config path from the config flag', function (): void {
    expect(Argument::fromArgv(['new', '--config=/tmp/team.config.json'])->configPath)->toBe('/tmp/team.config.json');
});

it('keeps flags out of the positionals', function (): void {
    $argument = Argument::fromArgv(['--dry-run', 'new', '--log=/tmp/x.log', 'demo-app', '--defaults']);

    expect($argument->command)->toBe('new')
        ->and($argument->presetName)->toBe('demo-app')
        ->and($argument->dryRun)->toBeTrue()
        ->and($argument->useDefaults)->toBeTrue()
        ->and($argument->logPath)->toBe('/tmp/x.log');
});
