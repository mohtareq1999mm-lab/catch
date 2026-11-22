<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * #143: rejected before any durable inbox row or lifecycle side effect.
 * The exception message never contains the presented or configured secret.
 */
class InvalidCarrierWebhookSignature extends RuntimeException {}
