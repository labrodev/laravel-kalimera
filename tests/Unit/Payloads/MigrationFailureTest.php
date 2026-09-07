<?php

declare(strict_types=1);

use Kalimera\Payloads\MigrationFailure;

it('reads a postgres duplicate sequence as a schema conflict', function (): void {
    $output = <<<'OUTPUT'
    SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "pg_class_relname_nsp_index"
    DETAIL:  Key (relname, relnamespace)=(migrations_id_seq, 2200) already exists. (Connection: pgsql, Host: pgsql, Port: 5432, Database: laravel, SQL: create table "migrations" ("id" serial not null primary key, "migration" varchar(255) not null, "batch" integer not null))
    OUTPUT;

    expect(MigrationFailure::fromOutput($output))->toBe(MigrationFailure::SchemaConflict);
});

it('reads engine-specific duplicate relation errors as schema conflicts', function (string $output): void {
    expect(MigrationFailure::fromOutput($output))->toBe(MigrationFailure::SchemaConflict);
})->with([
    'postgres duplicate table' => 'SQLSTATE[42P07]: Duplicate table: 7 ERROR:  relation "users" already exists',
    'postgres duplicate column' => 'SQLSTATE[42701]: Duplicate column: 7 ERROR:  column "email" of relation "users"',
    'mysql duplicate table' => "SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'users' already exists",
    'sqlite duplicate table' => 'SQLSTATE[HY000]: General error: 1 table "users" already exists',
]);

it('reads an unreachable database as a transient failure', function (string $output): void {
    expect(MigrationFailure::fromOutput($output))->toBe(MigrationFailure::Unavailable);
})->with([
    'refused' => 'SQLSTATE[08006] [7] connection to server at "pgsql" failed: Connection refused',
    'booting' => 'SQLSTATE[08006] [7] FATAL:  the database system is starting up',
    'unknown host' => 'SQLSTATE[08006] [7] could not translate host name "pgsql" to address',
    'mysql down' => 'SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, Host: mysql)',
    'mysql gone away' => 'SQLSTATE[HY000] [2006] MySQL server has gone away',
]);

it('retries a silent failure, having no evidence either way', function (): void {
    expect(MigrationFailure::fromOutput(''))->toBe(MigrationFailure::Unavailable)
        ->and(MigrationFailure::fromOutput("  \n "))->toBe(MigrationFailure::Unavailable);
});

it('treats an answered-but-refused migration as rejected rather than transient', function (): void {
    // The server replied, so its verdict will not change on a second attempt — even
    // though the wording matches nothing this enum knows about.
    expect(MigrationFailure::fromOutput('SQLSTATE[42601]: Syntax error at or near "creat"'))
        ->toBe(MigrationFailure::Rejected);
});

it('only retries the failures that more time can cure', function (): void {
    expect(MigrationFailure::Unavailable->shouldRetry())->toBeTrue()
        ->and(MigrationFailure::SchemaConflict->shouldRetry())->toBeFalse()
        ->and(MigrationFailure::Rejected->shouldRetry())->toBeFalse();
});
