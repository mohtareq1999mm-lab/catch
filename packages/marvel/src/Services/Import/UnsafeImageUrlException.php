<?php

namespace Marvel\Services\Import;

/**
 * Thrown by the SSRF guard when a URL fails the safe-import policy. The
 * reason is internal detail; callers translate it into their own
 * user-facing failure mode (bare exception, translated runtime exception).
 */
class UnsafeImageUrlException extends \RuntimeException
{
}
