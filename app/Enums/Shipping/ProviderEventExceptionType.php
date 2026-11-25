<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

/**
 * #151: classifications for provider events that must not transition state.
 *
 * These are internal operations signals, never customer-facing statuses.
 */
enum ProviderEventExceptionType: string
{
    case UNKNOWN_STATUS = 'unknown_status';
    case AMBIGUOUS_STATUS = 'ambiguous_status';
    case LOST_OR_DAMAGED = 'lost_or_damaged';
    case INVALID_MAPPING_CONFIG = 'invalid_mapping_config';
    case UNREGISTERED_PROVIDER = 'unregistered_provider';
    case UNMATCHED_SHIPMENT = 'unmatched_shipment';
    case MODE_MISMATCH = 'mode_mismatch';
    case TERMINAL_STATE_REVERSAL = 'terminal_state_reversal';
    case OUT_OF_ORDER_EVENT = 'out_of_order_event';

    // #187: cancellation requires provider confirmation; both refusal modes
    // are operationally distinct and never fabricate a local transition.
    case CANCEL_UNSUPPORTED = 'cancel_unsupported';
    case CANCEL_REJECTED = 'cancel_rejected';
}
