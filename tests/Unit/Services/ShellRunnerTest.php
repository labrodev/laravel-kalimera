<?php

declare(strict_types=1);

use Kalimera\Exceptions\CommandFailedException;
use Kalimera\Services\CommandOutputPrinter;
use Kalimera\Services\ShellRunner;
use Kalimera\Services\TranscriptLogger;

it('answers a probe truthfully during a dry run', function (): void {
    $shellRunner = new ShellRunner(dryRun: true);

    // A probe only asks a question, so a rehearsal that skipped it could not report the
    // answer — `docker info` deciding the preflight warning is the whole point.
    expect($shellRunner->probe(command: ['true']))->toBeTrue()
        ->and($shellRunner->probe(command: ['false']))->toBeFalse();
});

it('answers with the trimmed output of a command', function (): void {
    expect(new ShellRunner(dryRun: false)->ask(command: ['echo', 'hello']))->toBe('hello');
});

// A question with no answer, never an error: callers fall back to a default.
it('answers null when the command fails rather than throwing', function (): void {
    expect(new ShellRunner(dryRun: false)->ask(command: ['bash', '-c', 'echo partial; exit 3']))->toBeNull();
});

it('answers null when the program does not exist', function (): void {
    expect(new ShellRunner(dryRun: false)->ask(command: ['kalimera-definitely-not-a-program']))->toBeNull();
});

it('answers null when the command outlives the quiet timeout', function (): void {
    $shellRunner = new ShellRunner(dryRun: false, quietTimeoutSeconds: 0.3);

    expect($shellRunner->ask(command: ['bash', '-c', 'sleep 5; echo late']))->toBeNull();
});

it('answers a question during a dry run', function (): void {
    // Like a probe, asking changes nothing, so a rehearsal gets the real answer.
    expect(new ShellRunner(dryRun: true)->ask(command: ['echo', 'hello']))->toBe('hello');
});

it('answers in the given working directory', function (): void {
    $path = tempDir();

    expect(new ShellRunner(dryRun: false)->ask(command: ['pwd'], cwd: $path))->toBe($path);
});

it('skips a quiet side effect during a dry run', function (): void {
    $path = tempDir().'/side-effect-marker';
    $shellRunner = new ShellRunner(dryRun: true);

    expect($shellRunner->attemptQuietly(command: ['touch', $path]))->toBeFalse()
        ->and(file_exists($path))->toBeFalse();
});

it('executes nothing at all during a dry run', function (): void {
    $path = tempDir().'/dry-run-marker';
    $shellRunner = new ShellRunner(dryRun: true);

    // The whole promise of --dry-run, and the one thing no pipeline test can check: they
    // all substitute a fake runner, which records commands rather than declining them.
    // Without this, deleting the guard in runCommandOnce leaves the entire suite green
    // while a rehearsal really runs laravel new, sail up, sail down -v and git commit.
    $shellRunner->runCommand(command: ['touch', $path]);

    expect(file_exists($path))->toBeFalse();
});

it('announces the quiet side effect it is skipping during a dry run', function (): void {
    $shellRunner = new ShellRunner(dryRun: true);

    // `--dry-run` promises every command, and `sail down -v` is the only step in the plan
    // that destroys anything — the last one a rehearsal should keep to itself.
    $shellRunner->attemptQuietly(command: ['./vendor/bin/sail', 'down', '-v']);

    expect(promptOutput())->toContain('would ./vendor/bin/sail down -v');
});

it('performs a quiet side effect outside a dry run', function (): void {
    $path = tempDir().'/side-effect-marker';
    $shellRunner = new ShellRunner(dryRun: false);

    expect($shellRunner->attemptQuietly(command: ['touch', $path]))->toBeTrue()
        ->and(file_exists($path))->toBeTrue();
});

it('hands the tail of a failed command to the caller', function (): void {
    $shellRunner = new ShellRunner(
        commandOutputPrinter: new CommandOutputPrinter(interactive: false, heartbeatSeconds: 3600.0),
        dryRun: false,
    );

    $output = null;

    try {
        $shellRunner->runCommand(command: ['bash', '-c', 'echo "relation already exists" >&2; exit 1']);
    } catch (CommandFailedException $commandFailedException) {
        $output = $commandFailedException->output;
    }

    // Null here would mean the command never threw, which the assertion catches too.
    expect($output)->toContain('relation already exists');
});

it('keeps the progress line moving while the child is silent', function (): void {
    $stream = fopen('php://memory', 'r+');

    if ($stream === false) {
        throw new RuntimeException('The in-memory stream the printer writes into could not be opened.');
    }

    $shellRunner = new ShellRunner(
        commandOutputPrinter: new CommandOutputPrinter(interactive: true, stream: $stream),
        dryRun: false,
    );

    $shellRunner->runCommand(command: ['bash', '-c', 'sleep 1']);

    rewind($stream);
    $painted = substr_count((string) stream_get_contents($stream), "\r\033[2K");

    // One second of silence at a 100ms poll: several frames, not the single erase that
    // an output-driven painter would manage.
    expect($painted)->toBeGreaterThan(3);
});

it('captures output in full through the poll loop', function (): void {
    $shellRunner = new ShellRunner(
        commandOutputPrinter: new CommandOutputPrinter(interactive: false, heartbeatSeconds: 3600.0),
        dryRun: false,
    );

    $output = null;

    try {
        $shellRunner->runCommand(command: ['bash', '-c', 'for i in $(seq 1 200); do echo "line $i"; done; exit 1']);
    } catch (CommandFailedException $commandFailedException) {
        $output = $commandFailedException->output;
    }

    // The tail keeps the last 40, so 200 lines in must end at 200 and start at 161 —
    // nothing dropped between the final isRunning() drain and wait().
    expect($output)->toContain('line 200')
        ->and($output)->toContain('line 161')
        ->and($output)->not->toContain('line 160');
});

it('reports a command killed by its timeout as an ordinary failure', function (): void {
    $shellRunner = new ShellRunner(
        commandOutputPrinter: new CommandOutputPrinter(interactive: false, heartbeatSeconds: 3600.0),
        dryRun: false,
    );

    // SailStart's recovery and the retry budget both key off CommandFailedException. A
    // ProcessTimedOutException escaping past them would skip both.
    expect(fn () => $shellRunner->runCommand(command: ['bash', '-c', 'sleep 5'], timeout: 0.3))
        ->toThrow(CommandFailedException::class, 'timed out after 0.3 seconds');
});

it('reports a command killed by a signal as an ordinary failure', function (): void {
    $shellRunner = new ShellRunner(
        commandOutputPrinter: new CommandOutputPrinter(interactive: false, heartbeatSeconds: 3600.0),
        dryRun: false,
    );

    // The OOM killer taking a Sail image build is the realistic version of this. Like a
    // timeout it never reaches an exit code, so it has to arrive as the same kind of
    // failure or it skips the retry budget and every CommandFailedException recovery.
    expect(fn () => $shellRunner->runCommand(command: ['bash', '-c', 'kill -9 $$']))
        ->toThrow(CommandFailedException::class, 'killed by signal 9');
});

it('reports a command that cannot be launched as an ordinary failure', function (): void {
    $shellRunner = new ShellRunner(
        commandOutputPrinter: new CommandOutputPrinter(interactive: false, heartbeatSeconds: 3600.0),
        dryRun: false,
    );

    // A working directory that is not there fails before the process exists, so there is
    // no exit code and no signal — the third way out of runCommandOnce, and one that used
    // to escape as a raw Symfony exception past both recovery paths.
    expect(fn () => $shellRunner->runCommand(command: ['true'], cwd: tempDir().'/gone'))
        ->toThrow(CommandFailedException::class, 'Command aborted');
});

it('retries a command killed by its timeout like any other failure', function (): void {
    $shellRunner = new ShellRunner(
        commandOutputPrinter: new CommandOutputPrinter(interactive: false, heartbeatSeconds: 3600.0),
        dryRun: false,
        retryDelaySeconds: 0,
    );

    try {
        $shellRunner->runCommand(attempts: 2, command: ['bash', '-c', 'echo slow; sleep 5'], timeout: 0.3);
    } catch (CommandFailedException) {
        // The assertion below is what the test is about; the throw is expected.
    }

    // Two "Command failed — retrying" notices would mean three attempts; none would mean
    // the timeout bypassed the loop entirely.
    expect(substr_count(promptOutput(), 'retrying in'))->toBe(1);
});

it('keeps the tail of a command killed by its timeout', function (): void {
    $shellRunner = new ShellRunner(
        commandOutputPrinter: new CommandOutputPrinter(interactive: false, heartbeatSeconds: 3600.0),
        dryRun: false,
    );

    $output = null;

    try {
        $shellRunner->runCommand(command: ['bash', '-c', 'echo "waiting for the database"; sleep 5'], timeout: 0.3);
    } catch (CommandFailedException $commandFailedException) {
        $output = $commandFailedException->output;
    }

    // A command that hung usually said why it was slow just before it stopped.
    expect($output)->toContain('waiting for the database');
});

it('replays the failure tail once, on the attempt that gives up', function (): void {
    $stream = fopen('php://memory', 'r+');

    if ($stream === false) {
        throw new RuntimeException('The in-memory stream the printer writes into could not be opened.');
    }

    $shellRunner = new ShellRunner(
        commandOutputPrinter: new CommandOutputPrinter(errorStream: $stream, interactive: false, heartbeatSeconds: 3600.0),
        dryRun: false,
        retryDelaySeconds: 0,
    );

    try {
        $shellRunner->runCommand(attempts: 3, command: ['bash', '-c', 'echo "could not resolve host" >&2; exit 1']);
    } catch (CommandFailedException) {
        // Expected — three attempts, all failing.
    }

    rewind($stream);

    // Three copies of the same forty lines would bury the failure that finally sticks
    // under two that only led to a retry.
    expect(substr_count((string) stream_get_contents($stream), 'could not resolve host'))->toBe(1);
});

it('keeps a failure the caller is expecting off the terminal', function (): void {
    $stream = fopen('php://memory', 'r+');

    if ($stream === false) {
        throw new RuntimeException('The in-memory stream the printer writes into could not be opened.');
    }

    $shellRunner = new ShellRunner(
        commandOutputPrinter: new CommandOutputPrinter(errorStream: $stream, interactive: false, heartbeatSeconds: 3600.0),
        dryRun: false,
        retryDelaySeconds: 0,
    );

    try {
        $shellRunner->runCommand(command: ['bash', '-c', 'echo "found 5 errors" >&2; exit 1'], replayTail: false);
    } catch (CommandFailedException) {
        // Expected — this caller wants the exit code, not the report.
    }

    rewind($stream);

    // PHPStan failing on a fresh skeleton is the cue to write a baseline, not a broken
    // run, and replaying its report would present a planned step as a fault.
    expect((string) stream_get_contents($stream))->not->toContain('found 5 errors');
});

it('treats a quiet command that outruns its timeout as a failure, not an error', function (): void {
    $shellRunner = new ShellRunner(dryRun: false, quietTimeoutSeconds: 0.2);

    // `sail down -v` stops containers and removes volumes; on a loaded machine it can
    // outrun the cap. The contract says the run carries on regardless.
    expect($shellRunner->attemptQuietly(command: ['bash', '-c', 'sleep 5']))->toBeFalse();
});

it('treats a probe that outruns its timeout as a negative answer', function (): void {
    $shellRunner = new ShellRunner(dryRun: false, quietTimeoutSeconds: 0.2);

    // A wedged Docker daemon must reach PreflightCheck's own message, not throw past it.
    expect($shellRunner->probe(command: ['bash', '-c', 'sleep 5']))->toBeFalse();
});

it('treats a quiet command that cannot be launched as a failure', function (): void {
    $shellRunner = new ShellRunner(dryRun: false);

    // Every quiet call site passes a cwd. A missing one throws before the process ever
    // starts, which is the other way `run()` escapes without an exit code to report.
    expect($shellRunner->attemptQuietly(command: ['true'], cwd: tempDir().'/gone'))->toBeFalse();
});

it('records why a quiet command was abandoned in the transcript', function (): void {
    $path = tempDir().'/transcript.log';
    $shellRunner = new ShellRunner(
        dryRun: false,
        quietTimeoutSeconds: 0.2,
        transcriptLogger: new TranscriptLogger($path),
    );

    $shellRunner->attemptQuietly(command: ['bash', '-c', 'sleep 5']);

    // The run continues, so the transcript is the only place the reason survives.
    expect(file_get_contents($path))->toContain('quiet [aborted]:')
        ->and(file_get_contents($path))->toContain('exceeded the timeout');
});

it('runs a relative program exactly once when its working directory is not the current one', function (): void {
    // The shape of every `./vendor/bin/sail …` the scaffold issues: a relative program, a
    // cwd naming the application, and a runner whose own directory is somewhere else. On
    // macOS posix_spawn reported ENOENT for it while the child still ran, and the shell
    // fallback behind that report ran it a second time.
    $directory = tempDir();
    $script = $directory.'/vendor/bin/tool';
    mkdir(dirname($script), 0755, true);
    file_put_contents($script, "#!/usr/bin/env bash\necho run >> \"\$(dirname \"\$0\")/../../runs.log\"\n");
    chmod($script, 0755);

    expect(getcwd())->not->toBe($directory);

    $shellRunner = new ShellRunner(dryRun: false);

    foreach (range(1, 5) as $ignored) {
        $shellRunner->runCommand(command: ['./vendor/bin/tool'], cwd: $directory);
    }

    expect(file($directory.'/runs.log'))->toHaveCount(5);

    removeDir($directory);
});
