<?php

namespace Marvel\Services\Import;

use Exception;
use Marvel\Database\Models\Product;
use Marvel\Services\Import\ImageHandlers\UrlImageHandler;
use Throwable;

/**
 * Owns the per-row image download: URL validation, duplicate-media skip,
 * handler reuse, attachment and failure reporting. Used by both the inline
 * sheet path and the asynchronous image job.
 */
final class ImageImporter
{
    private ?UrlImageHandler $urlHandler = null;

    public function __construct(private readonly ImportReport $report)
    {
    }

    public function report(): ImportReport
    {
        return $this->report;
    }

    /**
     * @param  array<int, array{product_sku: string, image: string, row: int}>  $imageRows
     */
    public function ingest(array $imageRows): void
    {
        foreach ($imageRows as $row) {
            $sku = (string) ($row['product_sku'] ?? '');
            $image = (string) ($row['image'] ?? '');
            $rowIndex = (int) ($row['row'] ?? 0);

            if ($sku === '' || $image === '') {
                continue;
            }

            $this->import($sku, $image, $rowIndex);
        }
    }

    public function import(string $productSku, string $imageUrl, ?int $rowIndex = null): void
    {
        $imageUrl = trim($imageUrl);
        if (empty($imageUrl)) {
            return;
        }

        $product = Product::where('sku', $productSku)->first();
        if (!$product) {
            $this->report->addImageError(
                $rowIndex ?? 0,
                $productSku,
                __('message.IMPORT.PRODUCT.PRODUCT_NOT_FOUND', ['sku' => $productSku])
            );
            return;
        }

        try {
            if ($this->handler()->isValidUrl($imageUrl)) {
                $downloaded = $this->handler()->download($imageUrl);
                if ($downloaded) {
                    // Idempotency: skip if identical file already attached (hash comparison)
                    if ($this->isDuplicateMedia($product, $downloaded)) {
                        $this->handler()->cleanup($downloaded);
                    } else {
                        $this->handler()->attachToModel($product, $downloaded, 'products');
                        $this->handler()->cleanup($downloaded);
                    }
                } else {
                    $this->report->addImageError(
                        $rowIndex ?? 0,
                        $productSku,
                        __('message.IMPORT.PRODUCT.IMAGE_DOWNLOAD_FAILED', ['image_url' => $imageUrl])
                    );
                }
            } elseif (file_exists($imageUrl)) {
                if (!$this->isDuplicateMedia($product, $imageUrl)) {
                    $product->addMedia($imageUrl)->toMediaCollection('products');
                }
            } else {
                $this->report->addImageError(
                    $rowIndex ?? 0,
                    $productSku,
                    __('message.IMPORT.PRODUCT.IMAGE_INVALID_URL', ['image_url' => $imageUrl])
                );
            }
            // Images are auxiliary: success silent, failure non-fatal.
            // Must NOT inflate product-row counters (4104→4913).
        } catch (Exception $e) {
            $this->report->addImageError(
                $rowIndex ?? 0,
                $productSku,
                __('message.IMPORT.PRODUCT.IMAGE_PROCESSING_FAILED', [
                    'image_url' => $imageUrl,
                    'error_message' => $this->sanitizeErrorMessage($e),
                ])
            );
        }
    }

    private function handler(): UrlImageHandler
    {
        if ($this->urlHandler === null) {
            $this->urlHandler = new UrlImageHandler();
        }

        return $this->urlHandler;
    }

    /**
     * Check if the given file is already attached to the product (by content hash) to ensure re-import idempotency.
     */
    private function isDuplicateMedia(Product $product, string $filePath): bool
    {
        try {
            if (!file_exists($filePath)) {
                return false;
            }
            $newHash = @md5_file($filePath);
            if ($newHash === false) {
                return false;
            }
            $product->loadMissing('media');
            foreach ($product->getMedia('products') as $media) {
                $existingPath = $media->getPath();
                if (!file_exists($existingPath)) {
                    continue;
                }
                $existingHash = @md5_file($existingPath);
                if ($existingHash !== false && hash_equals($existingHash, $newHash)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return false;
    }

    private function sanitizeErrorMessage(Throwable $e): string
    {
        $msg = $e->getMessage();
        $msg = preg_replace('#/[^ ]*storage[^ ]*#i', '[storage path]', $msg) ?? $msg;
        $msg = preg_replace('#SQLSTATE\[[^\]]+\].*#i', 'Internal processing error', $msg) ?? $msg;
        if (strlen($msg) > 300) $msg = substr($msg, 0, 300) . '...';
        return trim($msg) !== '' ? trim($msg) : 'Unexpected error';
    }
}
