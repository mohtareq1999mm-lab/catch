<?php

namespace App\Exceptions;

/**
 * WMS write-write contention (first-winner-wins): duplicate creation,
 * double assignment, double claim. Surfaces as HTTP 409 via
 * WmsAdminController::conflict().
 *
 * The message is translated at the throw site (wms.* catalogue); the
 * TYPE (not the message text) drives the 409/422 distinction so locale
 * can never change the status code. Never branch on message substrings.
 */
class WmsConflictException extends \RuntimeException
{
}
