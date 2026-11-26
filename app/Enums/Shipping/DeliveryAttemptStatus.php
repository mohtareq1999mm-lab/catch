<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

enum DeliveryAttemptStatus: string
{
    case DELIVERED = 'delivered';
    case CUSTOMER_UNAVAILABLE = 'customer_unavailable';
    case WRONG_ADDRESS = 'wrong_address';
    case REFUSED = 'refused';
    case CASH_NOT_READY = 'cash_not_ready';
    case OTHER = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public function requiresReason(): bool
    {
        return $this === self::OTHER;
    }

    /**
     * Canonical #164 normalization shared by request validation, the recording
     * service, and #161 taxonomy integration.
     *
     * @param array<string,mixed> $input
     * @return array{status:?string,failure_reason:?string,error:?string,error_field:?string}
     */
    public static function normalizeAttempt(array $input): array
    {
        $rawStatus = is_string($input['status'] ?? null) ? trim($input['status']) : '';
        $status = self::tryFrom($rawStatus);

        if ($status === null) {
            return [
                'status' => $rawStatus === '' ? null : $rawStatus,
                'failure_reason' => null,
                'error' => 'invalid_status',
                'error_field' => 'status',
            ];
        }

        $rawReason = is_string($input['failure_reason'] ?? null) ? trim($input['failure_reason']) : null;
        $reason = ($rawReason === null || $rawReason === '') ? null : $rawReason;

        if ($status->requiresReason() && $reason === null) {
            return [
                'status' => $status->value,
                'failure_reason' => null,
                'error' => 'reason_required',
                'error_field' => 'failure_reason',
            ];
        }

        return [
            'status' => $status->value,
            'failure_reason' => $status === self::DELIVERED ? null : $reason,
            'error' => null,
            'error_field' => null,
        ];
    }
}
