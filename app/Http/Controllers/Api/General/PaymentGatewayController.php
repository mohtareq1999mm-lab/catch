<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Services\Currency\CurrencyService;
use App\Services\General\FastShippingService;
use App\Services\Payment\GatewaySettingsService;
use Illuminate\Http\JsonResponse;
use Marvel\Traits\ApiResponse;

class PaymentGatewayController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GatewaySettingsService $gateways,
        private CurrencyService $catalog,
        private FastShippingService $fastShipping,
    ) {}

    /**
     * GET /api/v1/general/payment-gateways (public, rate-limited).
     *
     * Client-safe availability snapshot so the storefront never hard-codes
     * payment options. Same data flow as the admin gateway list
     * (GatewaySettingsService merge + catalog-currency flag, trimmed to
     * public fields) plus the identical fast-shipping status block served
     * by GET /api/v1/general/fast-shipping/status.
     *
     * No availability verdict is computed here: a gateway disabled between
     * this read and order submit is still rejected by checkout (HTTP 422),
     * which stays the single authority.
     *
     * Note: display_name is admin-editable free text served to unauthenticated
     * consumers — frontends must escape it on render (same rule as every
     * other API-provided string).
     */
    public function index(): JsonResponse
    {
        return $this->apiResponse('Payment options fetched successfully.', 200, true, [
            'gateways' => $this->gateways->getPublicView($this->catalog->getCatalogCode()),
            'payment_methods' => [
                ['code' => 'online', 'display_name' => __('checkout.payment_method_online')],
                ['code' => 'cod', 'display_name' => __('checkout.payment_method_cod')],
                ['code' => 'pay_at_cashier', 'display_name' => __('checkout.payment_method_pay_at_cashier')],
            ],
            'fast_shipping' => $this->fastShipping->getStatus(),
        ]);
    }
}
