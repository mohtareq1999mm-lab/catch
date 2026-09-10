<?php

namespace App\Services\Digital;

use App\Enums\DigitalAssetCategory;
use App\Models\DigitalAsset;
use App\Models\DigitalLicenseKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class DigitalAssetService
{
    public const URL_MIME = 'text/uri-list';
    public const CREDENTIAL_MIME = 'text/plain';

    public function __construct(
        private AssetTypeRegistry $registry,
        private ExternalUrlValidator $urlValidator
    ) {}

    /**
     * Upload lifecycle (DIG-011 corrected):
     *
     *   1. validate EVERYTHING against actual bytes (no persistence yet)
     *   2. compute checksum from the real uploaded bytes
     *   3. write the physical file (server-generated UUID name)
     *   4. persist the DB row
     *   5. if persistence fails → compensate by deleting the just-written
     *      file, then rethrow — the two stores can never silently diverge.
     *
     * Filesystem operations are deliberately OUTSIDE the DB transaction:
     * transactions never roll back files, and the row only becomes visible
     * after its backing file already exists.
     */
    public function store(Product $product, UploadedFile $file, array $data = []): DigitalAsset
    {
        // Everything derived from bytes is captured BEFORE any filesystem
        // mutation: putFileAs() moves the upload, so late reads are unsafe.
        [$category, $detectedMime] = $this->validateUpload($file);

        // Server-generated name only: client filenames never touch the disk.
        $storedName = Str::uuid() . '.' . strtolower($file->getClientOriginalExtension());
        $directory = "digital-assets/{$product->id}";
        $path = "{$directory}/{$storedName}";

        $checksum = $this->checksum($file);

        if (!$this->disk()->putFileAs($directory, $file, $storedName)) {
            throw new HttpException(500, __('message.ERROR.DIGITAL_ASSET_UPLOAD_FAILED'));
        }

        try {
            return DB::transaction(function () use ($product, $file, $data, $path, $detectedMime, $checksum) {
                return DigitalAsset::create([
                    'product_id' => $product->id,
                    'type' => DigitalAsset::TYPE_FILE,
                    'disk' => 'private',
                    'path' => $path,
                    'original_name' => $data['original_name'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'mime' => $detectedMime,
                    'extension' => strtolower($file->getClientOriginalExtension()),
                    'size' => $file->getSize(),
                    'checksum' => $checksum,
                    'status' => DigitalAsset::STATUS_ACTIVE,
                    'sort_order' => (int) ($data['sort_order'] ?? $this->nextSortOrder($product->id)),
                ]);
            });
        } catch (Throwable $e) {
            $this->compensateOrphanFile($path);
            throw $e;
        }
    }

    /**
     * Metadata update (W6 widened): display_name/status/metadata join the
     * mutable set. File bytes stay immutable here — replacement is the
     * separate explicit operation. Checksum therefore never changes.
     */
    public function update(DigitalAsset $asset, array $data): DigitalAsset
    {
        $asset->update(array_intersect_key(
            $data,
            array_flip(['original_name', 'display_name', 'sort_order', 'status', 'metadata'])
        ));

        return $asset->refresh();
    }

    /**
     * W6 — explicit FILE replacement.
     *
     * Lifecycle (mirrors the W4 store guarantees):
     *   1. validate NEW bytes through the same registry/finfo pipeline
     *   2. checksum new bytes BEFORE any filesystem mutation
     *   3. write NEW file under its own UUID name
     *   4. transactionally swap row fields (path/mime/extension/size/checksum)
     *   5. persistence failure → compensate-delete the NEW file, old intact
     *   6. after commit → delete the OLD physical file (drift logged if unlink fails)
     *
     * uuid / original_name / display_name survive untouched; only byte-derived
     * fields are refreshed.
     */
    public function replace(DigitalAsset $asset, UploadedFile $file, array $data = []): DigitalAsset
    {
        if ($asset->type !== DigitalAsset::TYPE_FILE) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_NOT_REPLACEABLE'));
        }

        [$category, $detectedMime] = $this->validateUpload($file);

        $storedName = \Illuminate\Support\Str::uuid() . '.' . strtolower($file->getClientOriginalExtension());
        $directory = dirname($asset->path);
        $newPath = "{$directory}/{$storedName}";

        $checksum = $this->checksum($file);

        if (!$this->disk()->putFileAs($directory, $file, $storedName)) {
            throw new HttpException(500, __('message.ERROR.DIGITAL_ASSET_UPLOAD_FAILED'));
        }

        $oldPath = $asset->path;

        try {
            $updated = DB::transaction(function () use ($asset, $data, $newPath, $detectedMime, $checksum, $file) {
                $asset->forceFill([
                    'path' => $newPath,
                    'mime' => $detectedMime,
                    'extension' => strtolower($file->getClientOriginalExtension()),
                    'size' => $file->getSize(),
                    'checksum' => $checksum,
                ]);

                if (array_key_exists('display_name', $data)) {
                    $asset->display_name = $data['display_name'];
                }

                $asset->save();

                return $asset->refresh();
            });
        } catch (\Throwable $e) {
            $this->compensateOrphanFile($newPath);
            throw $e;
        }

        // Post-commit: retire the previous physical file. Failure leaves an
        // unreachable orphan that ops can sweep — surfaced loudly.
        if ($oldPath !== $newPath && !$this->disk()->delete($oldPath)) {
            Log::warning('Replaced digital asset old file could not be removed.', [
                'asset_uuid' => $asset->uuid,
                'path' => $oldPath,
            ]);
        }

        return $updated;
    }

    /**
     * URL asset (Workstream 5): represents an EXTERNALLY hosted resource.
     * No local file, no fake path, no checksum — integrity semantics do not
     * apply to resources this application does not own. The URL passes the
     * SSRF-safe validator; the server NEVER fetches it.
     */
    public function createUrl(Product $product, array $data): DigitalAsset
    {
        $normalized = $this->urlValidator->validate((string) ($data['external_url'] ?? ''));

        return DigitalAsset::create([
            'product_id' => $product->id,
            'type' => \App\Enums\DigitalAssetType::URL->value,
            'path' => null,
            'external_url' => $normalized,
            'original_name' => $data['original_name'] ?? (string) parse_url($normalized, PHP_URL_HOST),
            'mime' => self::URL_MIME,
            'size' => 0,
            'status' => DigitalAsset::STATUS_ACTIVE,
            'sort_order' => (int) ($data['sort_order'] ?? $this->nextSortOrder($product->id)),
        ]);
    }

    /**
     * LICENSE asset (decision A2): an empty key-pool container. Credentials
     * are provisioned through addLicenseKeys(); nothing secret lives on the
     * asset row itself.
     */
    public function createLicense(Product $product, array $data): DigitalAsset
    {
        return $this->createCredentialContainer($product, $data, \App\Enums\DigitalAssetType::LICENSE);
    }

    /**
     * ACCESS asset: a single encrypted credential stored ON the asset
     * (re-revealable access information, e.g. a course enrollment code).
     */
    public function createAccess(Product $product, array $data): DigitalAsset
    {
        if (empty($data['secret'])) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ACCESS_SECRET_REQUIRED'));
        }

        $asset = $this->createCredentialContainer($product, $data, \App\Enums\DigitalAssetType::ACCESS);
        $asset->forceFill(['secret' => (string) $data['secret']])->save();

        return $asset->refresh();
    }

    private function createCredentialContainer(Product $product, array $data, \App\Enums\DigitalAssetType $type): DigitalAsset
    {
        return DigitalAsset::create([
            'product_id' => $product->id,
            'type' => $type->value,
            'path' => null,
            'original_name' => $data['original_name'] ?? ($type->value === 'LICENSE' ? 'License Pool' : 'Access Credential'),
            'mime' => self::CREDENTIAL_MIME,
            'size' => 0,
            'status' => DigitalAsset::STATUS_ACTIVE,
            'sort_order' => (int) ($data['sort_order'] ?? $this->nextSortOrder($product->id)),
        ]);
    }

    /**
     * Bulk-provision encrypted keys into a LICENSE pool. Plaintext exists
     * only for the duration of this call; storage goes through the
     * 'encrypted' cast and responses carry counts exclusively.
     */
    public function addLicenseKeys(DigitalAsset $asset, array $keys): int
    {
        if ($asset->type !== \App\Enums\DigitalAssetType::LICENSE->value) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_LICENSE_POOL_ONLY'));
        }

        $max = (int) config('digital.licenses.max_batch_keys', 500);

        if (count($keys) > $max) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_TOO_LARGE', ['max' => $max]));
        }

        $created = 0;

        DB::transaction(function () use ($asset, $keys, &$created) {
            foreach ($keys as $key) {
                $trimmed = trim((string) $key);

                if ($trimmed === '') {
                    continue;
                }

                DigitalLicenseKey::create([
                    'asset_id' => $asset->id,
                    'encrypted_key' => $trimmed,
                ]);
                $created++;
            }
        });

        return $created;
    }

    /**
     * Delete lifecycle (DIG-011 corrected): the ROW is removed inside the
     * transaction; the physical file is removed AFTER commit.
     *
     * Invariants:
     *   - DB failure → nothing changed: row+file remain a consistent pair.
     *   - Post-commit FS failure → row is gone so no customer can ever be
     *     served a missing file; drift is surfaced via warning log for ops.
     */
    public function delete(DigitalAsset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $asset->delete();
        });

        if (!$this->disk()->delete($asset->path)) {
            Log::warning('Digital asset physical file could not be removed after row deletion.', [
                'asset_uuid' => $asset->uuid,
                'path' => $asset->path,
            ]);
        }
    }

    /**
     * Authoritative validation pipeline (DIG-004 corrected). Client MIME is
     * NEVER consulted: the detected content type comes from finfo on the
     * real uploaded bytes, and extension↔MIME must agree within one active
     * registry category.
     */
    private function validateUpload(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_INVALID_FILE'));
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, $this->registry->activeExtensions(), true)) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_INVALID_FILE'));
        }

        $detected = $this->detectMime($file);

        if (!in_array($detected, $this->registry->activeMimeTypes(), true)) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_INVALID_MIME'));
        }

        $category = $this->registry->resolveCompatibleCategory($extension, $detected);

        if ($category === null) {
            // Both individually allowed, but they disagree (spoof/mismatch).
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_MIME_MISMATCH'));
        }

        // A1 defense in depth: SOFTWARE can only ever reach an active
        // surface through explicit configuration; gate it again here so no
        // registry edit alone can enable executables.
        if (
            $category === DigitalAssetCategory::SOFTWARE
            && !config('digital.allow_software_assets', false)
        ) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_SOFTWARE_DISABLED'));
        }

        $maxKb = $this->registry->activeMaxKb($category->value);
        if ($maxKb > 0 && $file->getSize() > $maxKb * 1024) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_TOO_LARGE', ['max' => $maxKb]));
        }

        return [$category, $detected];
    }

    /**
     * Server-side content detection from the REAL uploaded bytes via
     * finfo. Never reads client-supplied MIME metadata.
     */
    private function detectMime(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();

        if (!$realPath || !is_file($realPath)) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_INVALID_FILE'));
        }

        $info = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $info->file($realPath);

        return is_string($detected) ? strtolower($detected) : null;
    }

    /** SHA-256 over actual bytes: lowercase hex, 64 chars, deterministic. */
    private function checksum(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    private function nextSortOrder(int $productId): int
    {
        return (int) DigitalAsset::where('product_id', $productId)->max('sort_order') + 1;
    }

    /** Compensation hook: remove a file whose DB row failed to persist. */
    private function compensateOrphanFile(string $path): void
    {
        if (!$this->disk()->delete($path)) {
            Log::warning('Digital asset orphan file could not be compensated after DB failure.', ['path' => $path]);
        }
    }

    /** Storage seam (private digital disk). Tests may override failure behavior via subclassing. */
    protected function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('private');
    }
}
