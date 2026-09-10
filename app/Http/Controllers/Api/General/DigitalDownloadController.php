<?php

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Services\Digital\DeliveryResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin customer-facing delivery controller. ALL gates and type dispatch
 * live in DeliveryResolver (Workstream 7 single chokepoint).
 */
class DigitalDownloadController extends Controller
{
    use ApiResponse;

    public function __construct(private DeliveryResolver $resolver) {}

    /**
     * List the authenticated user's digital entitlements together with
     * ready-to-use signed download URLs per asset.
     */
    public function index(Request $request): JsonResponse
    {
        $entitlements = DigitalEntitlement::query()
            ->where('user_id', $request->user()->id)
            ->with('orderItem')
            ->orderByDesc('created_at')
            ->get();

        // Single query for every license allocation this customer owns
        // (avoids N+1 across entitlements × license assets).
        $keysByEntitlement = \App\Models\DigitalLicenseKey::query()
            ->whereIn('allocated_entitlement_id', $entitlements->pluck('id'))
            ->get(['id', 'asset_id', 'allocated_entitlement_id', 'status', 'revealed_at'])
            ->groupBy('allocated_entitlement_id');

        $data = $entitlements->map(function (DigitalEntitlement $entitlement) use ($keysByEntitlement) {
            $accessible = $this->resolver->accessAllowed($entitlement);
            $allocated = $keysByEntitlement->get($entitlement->id, collect())
                ->keyBy('asset_id');

            return [
                'uuid' => $entitlement->uuid,
                'status' => $entitlement->status,
                'download_limit' => (int) $entitlement->download_limit,
                'download_count' => (int) $entitlement->download_count,
                'delivered_at' => $entitlement->delivered_at?->toIso8601String(),
                'revoked_at' => $entitlement->revoked_at?->toIso8601String(),
                'expires_at' => $entitlement->expires_at?->toIso8601String(),
                'product' => [
                    'id' => $entitlement->orderItem?->product_id,
                    'name' => $entitlement->orderItem?->product_name,
                ],
                'assets' => $entitlement->currentAssets()->map(fn (DigitalAsset $asset) => [
                    'uuid' => $asset->uuid,
                    'type' => $asset->type,
                    'original_name' => $asset->original_name,
                    'mime' => $asset->mime,
                    'size' => (int) $asset->size,

                    // W7 — additive delivery hint per asset kind.
                    'delivery_type' => match ($asset->type) {
                        \App\Enums\DigitalAssetType::FILE->value => 'download',
                        \App\Enums\DigitalAssetType::URL->value => 'redirect',
                        \App\Enums\DigitalAssetType::LICENSE->value,
                        \App\Enums\DigitalAssetType::ACCESS->value => 'reveal',
                        default => null,
                    },

                    // FILE: controlled signed URL (existing contract).
                    'download_url' => $asset->type === \App\Enums\DigitalAssetType::FILE->value
                        ? $this->signedUrl($entitlement, $asset)
                        : null,

                    // W5 — URL assets: the external target is disclosed ONLY
                    // while the entitlement is authorized. Revoked/pending/
                    // expired entitlements never see it.
                    'external_url' => ($asset->type === \App\Enums\DigitalAssetType::URL->value && $accessible)
                        ? $asset->external_url
                        : null,

                    // W5/W7 — LICENSE/ACCESS: metadata only, never the secret.
                    'reveal' => in_array($asset->type, [\App\Enums\DigitalAssetType::LICENSE->value, \App\Enums\DigitalAssetType::ACCESS->value], true)
                        ? [
                            'path' => "/api/v1/general/digital/license/{$entitlement->uuid}/{$asset->uuid}",
                            'available' => $accessible && (
                                $asset->type === \App\Enums\DigitalAssetType::ACCESS->value
                                    ? $asset->secret !== null
                                    : $allocated->has($asset->id)
                            ),
                            'revealed_at' => $allocated->get($asset->id)?->revealed_at?->toIso8601String(),
                        ]
                        : null,
                ])->values()->all(),
            ];
        })->values()->all();

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $data);
    }

    /**
     * W7 — thin signed-route wrapper: the resolver owns every gate and the
     * type dispatch (attachment / inline preview / range streaming).
     */
    public function download(Request $request, string $entitlementUuid, string $assetUuid): Response|JsonResponse
    {
        $mode = $request->query('mode', DeliveryResolver::MODE_DOWNLOAD);
        if (!in_array($mode, [DeliveryResolver::MODE_DOWNLOAD, DeliveryResolver::MODE_PREVIEW], true)) {
            $mode = DeliveryResolver::MODE_DOWNLOAD;
        }

        $entitlement = DigitalEntitlement::query()
            ->where('uuid', $entitlementUuid)
            ->first();

        if (!$entitlement) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        $asset = DigitalAsset::query()->where('uuid', $assetUuid)->first();

        if (!$asset) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        return $this->resolver->deliver($entitlement, $asset, $request, $mode);
    }

    /**
     * W5 — reveal a LICENSE pool key or ACCESS credential (delegates to the
     * delivery chokepoint; secrets decrypted only inside it).
     */
    public function reveal(Request $request, string $entitlementUuid, string $assetUuid): JsonResponse
    {
        $result = $this->resolver->revealCredential($request->user(), $entitlementUuid, $assetUuid);

        if ($result['status'] !== 200) {
            return $this->apiResponse(
                $result['payload']['message'],
                $result['status'],
                false
            );
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $result['payload']);
    }

    /**
     * W7 — audited external redirect for URL assets (auth-scoped; never
     * consumes a download credit; every access is audit-logged).
     */
    public function redirectToExternal(Request $request, string $entitlementUuid, string $assetUuid): Response|JsonResponse
    {
        return $this->resolver->redirectToExternal($request->user(), $entitlementUuid, $assetUuid);
    }

    private function signedUrl(DigitalEntitlement $entitlement, DigitalAsset $asset): ?string
    {
        if (!$this->resolver->accessAllowed($entitlement)) {
            return null;
        }

        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'general.digital.download',
            now()->addMinutes((int) config('digital.signed_url_ttl_minutes', 30)),
            ['entitlement' => $entitlement->uuid, 'asset' => $asset->uuid]
        );
    }
}
