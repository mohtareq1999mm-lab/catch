<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Enums\Shipping\DeliveryAttemptStatus;
use App\Services\Shipping\DeliveryAttemptOutcomeValidator;
use DomainException;
use PHPUnit\Framework\TestCase;

class DeliveryAttemptOutcomeValidatorTest extends TestCase
{
    public function test_fixed_taxonomy_values_are_exactly_the_six_canonical_values(): void
    {
        $expected = [
            'delivered',
            'customer_unavailable',
            'wrong_address',
            'refused',
            'cash_not_ready',
            'other',
        ];

        $this->assertSame($expected, DeliveryAttemptStatus::values());
        $this->assertSame($expected, array_map(
            static fn (DeliveryAttemptStatus $case): string => $case->value,
            DeliveryAttemptStatus::cases(),
        ));
        $this->assertTrue(DeliveryAttemptStatus::OTHER->requiresReason());
        $this->assertFalse(DeliveryAttemptStatus::DELIVERED->requiresReason());
    }

    public function test_every_defined_taxonomy_outcome_is_accepted(): void
    {
        $validator = new DeliveryAttemptOutcomeValidator();

        foreach (DeliveryAttemptStatus::cases() as $case) {
            $input = ['status' => $case->value];
            if ($case->requiresReason()) {
                $input['failure_reason'] = 'Operator supplied detail';
            }

            $this->assertSame(
                [
                    'status' => $case->value,
                    'failure_reason' => $case->requiresReason() ? 'Operator supplied detail' : null,
                ],
                $validator->normalize($input),
            );
        }
    }

    public function test_other_requires_a_reason(): void
    {
        $validator = new DeliveryAttemptOutcomeValidator();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('other');
        $validator->normalize(['status' => 'other', 'failure_reason' => '   ']);
    }

    public function test_unknown_taxonomy_outcome_is_rejected(): void
    {
        $validator = new DeliveryAttemptOutcomeValidator();

        $this->expectException(DomainException::class);
        $validator->normalize(['status' => 'not_a_real_status']);
    }

    public function test_delivered_discards_failure_reason(): void
    {
        $validator = new DeliveryAttemptOutcomeValidator();

        $this->assertSame(
            ['status' => 'delivered', 'failure_reason' => null],
            $validator->normalize(['status' => 'delivered', 'failure_reason' => 'stale reason']),
        );
    }
}
