<?php

namespace App\Services\Digital;

use App\Enums\DigitalAssetType;
use App\Models\DigitalAsset;
use App\Models\DigitalDownloadLog;
use App\Models\DigitalEntitlement;
use App\Models\DigitalLicenseKey;
use Marvel\Database\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Product;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workstream 7 — SINGLE delivery chokepoint for customer-facing digital
 * deliveries.
 *
 * Every gate from the W1–W6 security model lives here exactly once, in the
 * original order:
 *
 *   1. kill-switch            → 404
 *   2. entitlement exists     → 404
 *   3. status + expiry        → 403 (D7/D8 + W5 lazy expiry)
 *   4. product binding        → 404
 *   5. file/inactive check    → 404 (credit-safe ordering preserved)
 *   6. atomic limit increment → 403 (W6 unlimited sentinel honoured)
 *
 * Controllers are thin wrappers; no type-dispatch logic may live outside
 * this class.
 *
 * Modes:
 *   - download : attachment delivery; consumes a credit; audit-logged.
 *   - preview  : INLINE delivery for registry-previewable categories;
 *                never consumes a credit (the W7 spec does not authorise
 *                preview consumption); all authorization gates still run.
 */
class DeliveryResolver
{
    public const MODE_DOWNLOAD = 'download';
    public const MODE_PREVIEW = 'preview';

    public function __construct(private AssetTypeRegistry $registry) {}

    /**
     * Resolve a signed-route delivery (FILE / AUDIO / VIDEO attachments and
     * inline previews). Non-inlineable assets fall back to normal download
     * behaviour when preview is requested.
     */
    public function deliver(DigitalEntitlement $entitlement, DigitalAsset $asset, Request $request, string $mode = self::MODE_DOWNLOAD): Response
    {
        // Gate 1 — kill-switch.
        if (!config('digital.enabled', true)) {
            return $this->json(__('message.ERROR.DIGITAL_ENTITLEMENT_NOT_ACCESSIBLE'), 404);
        }

        // Gate 2 — entitlement existence (signed routes carry no identity).
        if (!$entitlement->exists) {
            return $this->json(NOT_FOUND, 404);
        }
        $entitlement->loadMissing('orderItem');

        // Gate 3 — delivered status + lazy expiry.
        if (!$this->accessAllowed($entitlement)) {
            return $this->json(__('message.ERROR.DIGITAL_ENTITLEMENT_NOT_ACCESSIBLE'), 403);
        }

        // Gate 4 — asset must belong to the purchased product.
        if ((int) $asset->product_id !== (int) $entitlement->orderItem?->product_id) {
            return $this->json(NOT_FOUND, 404);
        }

        $isFile = $asset->type === DigitalAssetType::FILE->value;
        $previewRequested = $mode === self::MODE_PREVIEW;

        // FILE-family only beyond this point; other types use dedicated
        // authenticated endpoints (URL redirect / credential reveal).
        if (!$isFile) {
            return $this->json(__('message.ERROR.DIGITAL_ENTITLEMENT_NOT_ACCESSIBLE'), 403);
        }

        $category = $this->registry->resolveCategory(
            (string) $asset->extension,
            (string) $asset->mime
        );

        $inlineCapable = $category !== null && $this->registry->isPreviewable($category);
        $inline = $previewRequested && $inlineCapable;

        // Gate 5a — inactive assets are undeliverable in any mode.
        if (($asset->status ?? DigitalAsset::STATUS_ACTIVE) !== DigitalAsset::STATUS_ACTIVE) {
            return $this->json(NOT_FOUND, 404);
        }

        // Gate 5b — physical file must exist BEFORE consuming a credit.
        $disk = Storage::disk($asset->disk);
        if (!$disk->exists($asset->path)) {
            return $this->json(NOT_FOUND, 404);
        }

        $consumesCredit = !$inline;

        // Gate 6 — race-safe atomic credit (unlimited sentinel honoured).
        if ($consumesCredit) {
            $affected = DB::update(
                'UPDATE digital_entitlements SET download_count = download_count + 1 WHERE id = ? AND status = ? AND (download_limit = 0 OR download_count < download_limit)',
                [$entitlement->id, DigitalEntitlement::STATUS_DELIVERED]
            );

            if ($affected === 0) {
                return $this->json(__('message.ERROR.DIGITAL_DOWNLOAD_LIMIT_REACHED'), 403);
            }

            DB::table('digital_download_logs')->insert([
                'entitlement_id' => $entitlement->id,
                'asset_id' => $asset->id,
                'ip_hash' => hash('sha256', $request->ip() . '|' . config('app.key')),
                'ua_hash' => hash('sha256', substr((string) $request->userAgent(), 0, 512) . '|' . config('app.key')),
                'downloaded_at' => now(),
            ]);
        }

        // W7 — RFC 7233 single-range guard.
        //  - Valid single numeric range: unsatisfiable requests get the
        //    standard 416 + bytes */total; valid ones are left to
        //    BinaryFileResponse (which clamps end offsets).
        //  - Malformed or multi-range headers: stripped so delivery degrades
        //    to a plain full-body 200 (lenient fallback used by this Symfony
        //    build, which would otherwise emit a bogus 206).
        $rangeHeader = $request->headers->get('Range');
        $fileSize = (int) $asset->size;

        if ($rangeHeader !== null) {
            if (preg_match('/^bytes=(\d*)-(\d*)$/i', trim($rangeHeader), $m) && ($m[1] !== '' || $m[2] !== '')) {
                if ($m[1] === '') {
                    if ((int) $m[2] === 0) {
                        return $this->unsatisfiable($fileSize);   // suffix of length zero
                    }
                } elseif ((int) $m[1] > max(0, $fileSize - 1)) {
                    return $this->unsatisfiable($fileSize);
                }
            } else {
                $request->headers->remove('Range');
            }
        }

        $filename = $this->sanitizeFilename($asset->original_name) . '.' . pathinfo($asset->path, PATHINFO_EXTENSION);

        // Preferred delivery: BinaryFileResponse over the REAL local path —
        // natively implements HTTP Range (206/Content-Range/416) while
        // streaming in small chunks (never loads binaries into memory).
        if (method_exists($disk, 'path')) {
            try {
                $absolute = $disk->path($asset->path);
            } catch (\Throwable) {
                $absolute = null;
            }

            if ($absolute !== null && is_file($absolute)) {
                $binary = new \Symfony\Component\HttpFoundation\BinaryFileResponse(
                    $absolute,
                    Response::HTTP_OK,
                    ['Cache-Control' => 'private, no-store'],
                    false // not public
                );
                $binary->setContentDisposition(
                    $inline
                        ? 'inline'
                        : 'attachment',
                    $filename,
                    $filename
                );
                $binary->headers->set('Content-Type', $asset->mime ?: 'application/octet-stream');

                return $binary;
            }
        }

        // Fallback for non-local adapters: streamed response WITHOUT native
        // Range support (documented limitation until an adapter-specific
        // streaming strategy exists).
        return $disk->response($asset->path, $filename, [
            'Content-Type' => $asset->mime ?: 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * W5 reveal semantics relocated into the chokepoint: ownership at read
     * time, delivered+expiry gate, product binding, one-time enforcement.
     * Returns an HTTP-status/payload pair for the controller to envelope.
     *
     * @return array{status: int, payload: array}
     */
    public function revealCredential(User $user, string $entitlementUuid, string $assetUuid): array
    {
        if (!config('digital.enabled', true)) {
            return ['status' => 404, 'payload' => ['message' => __('message.ERROR.DIGITAL_ENTITLEMENT_NOT_ACCESSIBLE')]];
        }

        $entitlement = DigitalEntitlement::query()
            ->where('uuid', $entitlementUuid)
            ->where('user_id', $user->id)
            ->first();

        if (!$entitlement) {
            return ['status' => 404, 'payload' => ['message' => NOT_FOUND]];
        }

        if (!$this->accessAllowed($entitlement)) {
            return ['status' => 403, 'payload' => ['message' => __('message.ERROR.DIGITAL_ENTITLEMENT_NOT_ACCESSIBLE')]];
        }

        $asset = DigitalAsset::query()->where('uuid', $assetUuid)->first();

        if (!$asset || (int) $asset->product_id !== (int) $entitlement->orderItem?->product_id) {
            return ['status' => 404, 'payload' => ['message' => NOT_FOUND]];
        }

        if ($asset->type === DigitalAssetType::ACCESS->value) {
            if ($asset->secret === null) {
                return ['status' => 404, 'payload' => ['message' => __('message.ERROR.DIGITAL_LICENSE_NOT_ALLOCATED')]];
            }

            return ['status' => 200, 'payload' => [
                'type' => 'access',
                'credential' => $asset->secret,   // decrypted by cast
            ]];
        }

        if ($asset->type !== DigitalAssetType::LICENSE->value) {
            return ['status' => 404, 'payload' => ['message' => NOT_FOUND]];
        }

        $key = DigitalLicenseKey::query()
            ->where('asset_id', $asset->id)
            ->where('allocated_entitlement_id', $entitlement->id)
            ->first();

        if (!$key) {
            return ['status' => 404, 'payload' => ['message' => __('message.ERROR.DIGITAL_LICENSE_NOT_ALLOCATED')]];
        }

        if (config('digital.licenses.one_time_reveal', true) && $key->revealed_at !== null) {
            return ['status' => 403, 'payload' => ['message' => __('message.ERROR.DIGITAL_LICENSE_ALREADY_REVEALED')]];
        }

        $key->forceFill(['revealed_at' => now()])->save();

        return ['status' => 200, 'payload' => [
            'type' => 'license',
            'credential' => $key->encrypted_key,
            'revealed_at' => $key->revealed_at?->toIso8601String(),
        ]];
    }

    /**
     * W7 — AUDITED external redirect for URL assets (auth-scoped route).
     * The stored normalized URL is the source of truth; this application
     * never fetches it. Access is audited in digital_download_logs but does
     * NOT consume download credits.
     */
    public function redirectToExternal(User $user, string $entitlementUuid, string $assetUuid): RedirectResponse|Response
    {
        if (!config('digital.enabled', true)) {
            return $this->json(__('message.ERROR.DIGITAL_ENTITLEMENT_NOT_ACCESSIBLE'), 404);
        }

        $entitlement = DigitalEntitlement::query()
            ->where('uuid', $entitlementUuid)
            ->where('user_id', $user->id)
            ->first();

        if (!$entitlement) {
            return $this->json(NOT_FOUND, 404);
        }

        if (!$this->accessAllowed($entitlement)) {
            return $this->json(__('message.ERROR.DIGITAL_ENTITLEMENT_NOT_ACCESSIBLE'), 403);
        }

        $asset = DigitalAsset::query()->where('uuid', $assetUuid)->first();

        if (
            !$asset
            || $asset->type !== DigitalAssetType::URL->value
            || (int) $asset->product_id !== (int) $entitlement->orderItem?->product_id
            || $asset->external_url === null
        ) {
            return $this->json(NOT_FOUND, 404);
        }

        DB::table('digital_download_logs')->insert([
            'entitlement_id' => $entitlement->id,
            'asset_id' => $asset->id,
            'ip_hash' => hash('sha256', request()->ip() . '|' . config('app.key')),
            'ua_hash' => hash('sha256', substr((string) request()->userAgent(), 0, 512) . '|' . config('app.key')),
            'downloaded_at' => now(),
        ]);

        return new RedirectResponse($asset->external_url, 302);
    }

    private function unsatisfiable(int $fileSize): Response
    {
        return new \Symfony\Component\HttpFoundation\Response(
            '',
            416,
            ['Content-Range' => 'bytes */' . $fileSize]
        );
    }

    /** Delivered + unexpired predicate (W5/W6 authority). */
    public function accessAllowed(DigitalEntitlement $entitlement): bool
    {
        if ($entitlement->status !== DigitalEntitlement::STATUS_DELIVERED) {
            return false;
        }

        return $entitlement->expires_at === null || $entitlement->expires_at->isFuture();
    }

    private function json(string $message, int $status): Response
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
            'success' => false,
        ], $status);
    }

    private function sanitizeFilename(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $clean = preg_replace('/[^A-Za-z0-9\-_ ]+/', '-', $base) ?? 'download';

        return trim(mb_substr($clean, 0, 120)) ?: 'download';
    }
}
