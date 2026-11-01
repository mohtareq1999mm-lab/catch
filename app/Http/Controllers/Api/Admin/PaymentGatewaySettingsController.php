<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Audit\ActivityAuditService;
use App\Audit\ActivitySnapshot;
use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\UpdateGatewaySettingsRequest;
use App\Services\Currency\CurrencyService;
use App\Services\Payment\GatewaySettingsService;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\Settings;
use Marvel\Traits\ApiResponse;

class PaymentGatewaySettingsController extends Controller
{
    use ApiResponse, HasCache;

    public function __construct(
        private GatewaySettingsService $gateways,
        private CurrencyService $catalog,
    ) {}

    /**
     * GET /api/v1/admin/payment-gateways
     *
     * Admin view of every known gateway sorted by sort_order, plus the
     * catalog currency and a per-gateway supports_catalog_currency preview.
     * Secrets are never serialized (see GatewaySettingsService).
     */
    public function index(): JsonResponse
    {
        $catalogCode = strtoupper($this->catalog->getCatalogCode());

        $gateways = array_map(function (array $row) use ($catalogCode) {
            $supported = array_map('strtoupper', (array) ($row['supported_currencies'] ?? []));
            $row['supports_catalog_currency'] = in_array($catalogCode, $supported, true);

            return $row;
        }, $this->gateways->getAdminView());

        return $this->apiResponse(__('message.MESSAGE.PAYMENT_GATEWAYS_FETCHED'), 200, true, [
            'catalog_currency' => $catalogCode,
            'gateways' => $gateways,
        ]);
    }

    /**
     * PUT /api/v1/admin/payment-gateways/{code}
     *
     * Persists ONLY the {enabled, display_name, sort_order} allowlist into
     * settings.options['payment_gateways'][code]. Audited asynchronously via
     * the canonical ActivitySnapshot dispatch. Flushes the tagged
     * site-settings cache so the website payload reflects the new state.
     */
    public function update(UpdateGatewaySettingsRequest $request, string $code): JsonResponse
    {
        $before = $this->gateways->adminRow($code);

        if ($before === null) {
            return $this->apiResponse(__('message.ERROR.PAYMENT_GATEWAY_NOT_FOUND'), 404, false);
        }

        $row = $this->gateways->updateOverride($code, $request->validated());

        // Drop the tagged site-settings payload served by
        // GET /api/v1/general/settings so a gateway toggle is reflected for
        // everyone immediately (same flush the settings update path performs).
        // The public payment-gateways endpoint and checkout itself read the
        // settings row uncached, so they were never stale.
        $this->flushTag(FrontendResource::SETTINGS->value);

        $user = $request->user();

        ActivityAuditService::dispatch(new ActivitySnapshot(
            logName: 'settings',
            event: 'payment_gateway_updated',
            description: (string) __('activity.settings_updated'),
            subjectType: Settings::class,
            subjectId: (int) Settings::query()->value('id'),
            causerType: $user ? get_class($user) : null,
            causerId: $user ? (int) $user->getAuthIdentifier() : null,
            old: $before,
            new: $row,
            context: ['gateway' => $code, 'source' => 'admin-api'],
        ));

        return $this->apiResponse(__('message.MESSAGE.PAYMENT_GATEWAY_UPDATED'), 200, true, $row);
    }
}
