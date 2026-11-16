<?php

namespace App\Contracts;

/**
 * A business rejection that the HTTP exception Handler renders as one of the
 * project's standard error envelopes.
 *
 * Implementations carry the whole rendering decision (status, localized
 * message, field errors, additive data) so no controller has to catch them and
 * build a response of its own.
 */
interface ApiRejection
{
    /** HTTP status for this rejection (4xx for business failures). */
    public function statusCode(): int;

    /** Localized, user-facing message. */
    public function message(): string;

    /** Field-scoped errors, keyed the same way as validation errors. */
    public function errors(): array;

    /** Additive payload (for example a machine-readable rejection reason). */
    public function data(): array;
}
