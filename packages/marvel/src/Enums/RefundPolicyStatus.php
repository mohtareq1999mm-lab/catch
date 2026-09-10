<?php


namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Class RoleType
 * @package App\Enums
 */
final class RefundPolicyStatus extends Enum
{
    public const APPROVED = 'approved';
    public const PENDING = 'pending';
    public const REJECTED = 'rejected';
    public const PROCESSING = 'processing';
}
