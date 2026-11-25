<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** #174: no existing return request is suitable; never create a fallback lifecycle. */
class CarrierReturnLinkSkipped extends RuntimeException {}
