<?php

namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * @deprecated Use FileOperationType — kept for backward compatibility.
 */
final class ImportType extends Enum
{
    const PRODUCT_IMPORT = FileOperationType::PRODUCT_IMPORT;
    const PRODUCT_EXPORT = FileOperationType::PRODUCT_EXPORT;
    const CATEGORY_IMPORT = FileOperationType::CATEGORY_IMPORT;
    const CATEGORY_EXPORT = FileOperationType::CATEGORY_EXPORT;
    const BRAND_IMPORT = FileOperationType::BRAND_IMPORT;
    const BRAND_EXPORT = FileOperationType::BRAND_EXPORT;
    const CATEGORY_BULK_DELETE = FileOperationType::CATEGORY_BULK_DELETE;
}
