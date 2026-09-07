<?php

declare(strict_types=1);

namespace Kalimera\Payloads;

/**
 * Why a `migrate` run failed, and therefore whether waiting can cure it.
 *
 * Retrying only ever helps when the database could not be reached — `sail up --wait`
 * returns before Postgres finishes its own startup often enough to be worth absorbing.
 * Once the server has answered, its verdict will be identical a second and third time, so
 * the question asked here is "did we get through?", not "which error is this?". That
 * inverts a brittle catalogue of failure wordings into a small list of connection-level
 * signals, which are far more stable across engines and versions.
 */
enum MigrationFailure
{
    /** The database never answered. More time may be all it needs. */
    case Unavailable;

    /** The database answered and refused, over schema an earlier run left behind. */
    case SchemaConflict;

    /** The database answered and refused for some other reason. */
    case Rejected;

    /**
     * SQL class 08 is "connection exception" in the standard; MySQL reports the same
     * conditions through its own 2002/2003/2006 client errors.
     */
    private const array UNAVAILABLE_MARKERS = [
        'sqlstate[08',
        '[2002]',
        '[2003]',
        '[2006]',
        'connection refused',
        'connection timed out',
        'could not connect',
        'could not translate host name',
        'is starting up',
        'server closed the connection',
        'terminating connection',
    ];

    /**
     * Recognised only to explain the failure in the warning — every case below is treated
     * the same way. Postgres reports a duplicate relation as a unique violation on its
     * pg_class catalog, MySQL as 42S01, SQLite in plain words.
     */
    private const array CONFLICT_MARKERS = [
        'already exists',
        'pg_class',
        'pg_type',
        'sqlstate[42701]',
        'sqlstate[42710]',
        'sqlstate[42p06]',
        'sqlstate[42p07]',
        'sqlstate[42s01]',
        'sqlstate[42s21]',
    ];

    public static function fromOutput(string $output): self
    {
        $haystack = mb_strtolower($output);

        // A command that failed without saying anything is the one case with no evidence
        // either way; a retry costs seconds, so spend them rather than guess.
        if (trim($haystack) === '') {
            return self::Unavailable;
        }

        if (self::matches($haystack, self::UNAVAILABLE_MARKERS)) {
            return self::Unavailable;
        }

        return self::matches($haystack, self::CONFLICT_MARKERS) ? self::SchemaConflict : self::Rejected;
    }

    public function shouldRetry(): bool
    {
        return $this === self::Unavailable;
    }

    /**
     * @param  list<string>  $markers
     */
    private static function matches(string $haystack, array $markers): bool
    {
        return array_any($markers, fn (string $marker): bool => str_contains($haystack, $marker));
    }
}
