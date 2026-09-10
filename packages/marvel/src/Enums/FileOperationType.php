<?php

namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Canonical operation type for file operations (imports/exports/bulk deletes).
 *
 * This is the single source of truth for operation discrimination.
 * Keep resource_type, product_type, file_format, and signal_type separate.
 */
final class FileOperationType extends Enum
{
    const PRODUCT_IMPORT = 'product-import';
    const PRODUCT_EXPORT = 'product-export';
    const CATEGORY_IMPORT = 'category-import';
    const CATEGORY_EXPORT = 'category-export';
    const BRAND_IMPORT = 'brand-import';
    const BRAND_EXPORT = 'brand-export';
    const CATEGORY_BULK_DELETE = 'category-bulk-delete';

    /**
     * Map legacy short values to canonical operation values.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $map = [
            'product' => self::PRODUCT_IMPORT,
            'category' => self::CATEGORY_IMPORT,
            'brand' => self::BRAND_IMPORT,
        ];

        return $map[$value] ?? $value;
    }

    public static function isImport(string $value): bool
    {
        return in_array($value, [
            self::PRODUCT_IMPORT,
            self::CATEGORY_IMPORT,
            self::BRAND_IMPORT,
        ], true);
    }

    public static function isExport(string $value): bool
    {
        return in_array($value, [
            self::PRODUCT_EXPORT,
            self::CATEGORY_EXPORT,
            self::BRAND_EXPORT,
        ], true);
    }
}
