<?php

namespace Tests\Unit\Support;

use App\Support\UniqueViolation;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

class UniqueViolationTest extends TestCase
{
    private function queryException(array $errorInfo, int $driverCode): QueryException
    {
        $pdo = new PDOException('SQLSTATE[' . ($errorInfo[0] ?? '00000') . ']: db error', $driverCode);
        $pdo->errorInfo = $errorInfo;

        return new QueryException('mysql', 'insert into x values (1)', [], $pdo);
    }

    public static function duplicateKeyProvider(): array
    {
        return [
            'mysql driver error 1062' => [['23000', 1062], 23000],
            'sqlstate 23000 only' => [['23000', 0], 23000],
            'postgres 23505' => [['23505', 7], 23505],
            'string driver code 1062' => [['23000', 0], 1062],
        ];
    }

    /** @dataProvider duplicateKeyProvider */
    public function test_detects_duplicate_key_errors(array $errorInfo, int $driverCode): void
    {
        $this->assertTrue(UniqueViolation::detect($this->queryException($errorInfo, $driverCode)));
    }

    public function test_ignores_foreign_key_errors(): void
    {
        $this->assertFalse(
            UniqueViolation::detect($this->queryException(['23000', 1452], 1452))
        );
    }

    public function test_ignores_deadlocks(): void
    {
        $this->assertFalse(
            UniqueViolation::detect($this->queryException(['40001', 1213], 40001))
        );
    }
}
