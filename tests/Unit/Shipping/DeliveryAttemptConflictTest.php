<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Services\Shipping\DeliveryAttemptService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use PHPUnit\Framework\TestCase;

class DeliveryAttemptConflictTest extends TestCase
{
    public function test_unique_constraint_exception_is_detected_without_message_sniffing(): void
    {
        $exception = $this->queryException(
            'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry',
            ['23000', 1062, 'Duplicate entry'],
            1062,
            true
        );

        $this->assertTrue((new DeliveryAttemptService())->isAttemptNumberConflict($exception));
    }

    public function test_non_target_unique_constraint_error_is_not_misclassified(): void
    {
        $foreignKeyViolation = $this->queryException(
            'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row',
            ['23000', 1452, 'Foreign key constraint fails'],
            1452
        );

        $this->assertFalse((new DeliveryAttemptService())->isAttemptNumberConflict($foreignKeyViolation));
    }

    public function test_postgres_unique_sqlstate_is_detected(): void
    {
        $exception = $this->queryException(
            'SQLSTATE[23505]: Unique violation',
            ['23505', 7, 'Duplicate key'],
            23505
        );

        $this->assertTrue((new DeliveryAttemptService())->isAttemptNumberConflict($exception));
    }

    private function queryException(string $message, array $errorInfo, int $driverCode, bool $unique = false): QueryException
    {
        $pdo = new PDOException($message, $driverCode);
        $pdo->errorInfo = $errorInfo;

        $class = $unique ? UniqueConstraintViolationException::class : QueryException::class;

        return new $class('mysql', 'insert into shipment_delivery_attempts values (?)', [], $pdo);
    }
}
