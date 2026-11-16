<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * One detector for "this write lost the race to a UNIQUE constraint".
 *
 * Optimistic-create flows (one cart per identity, idempotency claim, order
 * claim, login merge) catch this and resolve the winner's row instead of
 * surfacing a 500. Engine codes: MySQL driver error 1062, SQLSTATE 23000
 * (MySQL/SQLite), Postgres 23505.
 */
final class UniqueViolation
{
    public static function detect(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $code = (string) $e->getCode();

        return ($errorInfo[1] ?? null) === 1062
            || $code === '23000'
            || $code === '23505'
            || $code === '1062';
    }
}
