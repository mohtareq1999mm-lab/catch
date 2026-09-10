<?php

namespace Marvel\Enums;

use BenSampo\Enum\Enum;

final class ImportStatus extends Enum
{
    const PENDING = 'pending';
    const PROCESSING = 'processing';
    const COMPLETED = 'completed';
    const COMPLETED_WITH_ERRORS = 'completed_with_errors';
    const FAILED = 'failed';
    const CANCELLING = 'cancelling';
    const CANCELLED = 'cancelled';
}
