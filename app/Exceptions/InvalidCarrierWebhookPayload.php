<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** #143: verified-but-unusable payload; rejected before persistence. */
class InvalidCarrierWebhookPayload extends RuntimeException {}
