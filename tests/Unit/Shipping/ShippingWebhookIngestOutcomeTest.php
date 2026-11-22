<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Enums\Shipping\ShippingWebhookIngestOutcome;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/** The controller maps this enum directly to the existing API envelope. */
class ShippingWebhookIngestOutcomeTest extends TestCase
{
    public function test_status_translation_and_success_mapping_are_pinned(): void
    {
        $expected = [
            [ShippingWebhookIngestOutcome::ACCEPTED, 200, true],
            [ShippingWebhookIngestOutcome::DUPLICATE, 200, true],
            [ShippingWebhookIngestOutcome::INVALID_SIGNATURE, 401, false],
            [ShippingWebhookIngestOutcome::INVALID_PAYLOAD, 400, false],
            [ShippingWebhookIngestOutcome::UNAVAILABLE, 503, false],
            [ShippingWebhookIngestOutcome::RATE_LIMITED, 429, false],
        ];

        foreach ($expected as [$outcome, $status, $success]) {
            $this->assertSame($status, $outcome->httpStatus());
            $this->assertSame($success, $outcome->success());
            $this->assertTrue(Lang::has($outcome->messageKey(), 'en'));
            $this->assertTrue(Lang::has($outcome->messageKey(), 'ar'));
        }
    }
}
