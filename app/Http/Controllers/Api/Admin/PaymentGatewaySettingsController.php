<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Audit\ActivityAuditService;
use App\Audit\ActivitySnapshot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\UpdateGatewaySettingsRequest;
use App\Services\Currency\CurrencyService;
use App\Services\Payment\GatewaySettingsService;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\Settings;
use Marvel\Traits\ApiResponse;

class PaymentGatewaySettingsController extends Controller
{
    use ApiResponse;

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

        return $this->apiResponse('Payment gateways fetched successfully.', 200, true, [
            'catalog_currency' => $catalogCode,
            'gateways' => $gateways,
        ]);
    }

    /**
     * PUT /api/v1/admin/payment-gateways/{code}
     *
     * Persists ONLY the {enabled, display_name, sort_order} allowlist into
     * settings.options['payment_gateways'][code]. Audited asynchronously via
     * the canonical ActivitySnapshot dispatch.
     */
    public function update(UpdateGatewaySettingsRequest $request, string $code): JsonResponse
    {
        $before = $this->gateways->adminRow($code);

        if ($before === null) {
            return $this->apiResponse('Payment gateway not found.', 404, false);
        }

        $row = $this->gateways->updateOverride($code, $request->validated());

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

        return $this->apiResponse('Payment gateway updated successfully.', 200, true, $row);
    }
}
