<?php

use App\Support\ProductTypeSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Repair product_type values that MySQL silently coerced to ''.
     *
     * MySQL runs with `strict => false` in this project, so writing a value an
     * ENUM cannot represent (for example boolean false from an older writer)
     * stores '' rather than failing. This migration widens the ENUM when every
     * existing value is representable, then rewrites invalid values to the
     * canonical default. Unknown ENUM values are never guessed: the migration
     * stops so an operator can decide how to map them.
     */
    public function up(): void
    {
        if (!app(ProductTypeSchema::class)->repair()) {
            throw new RuntimeException(
                'products.product_type could not be repaired automatically. '
                . 'Either the column is missing, it is not an ENUM, or it contains values that cannot be '
                . 'mapped to simple/variable. Inspect the column and migrate it manually before deploying.'
            );
        }
    }

    public function down(): void
    {
        // Data repair is intentionally irreversible: restoring '' would only
        // reintroduce the corrupt state this migration exists to remove.
    }
};
