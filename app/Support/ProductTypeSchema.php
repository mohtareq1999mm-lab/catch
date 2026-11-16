<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Enums\ProductType;

/**
 * Contract guard for the products.product_type column.
 *
 * Why this exists:
 * - This project runs MySQL with `strict => false`, so a value that an ENUM
 *   cannot represent is stored as '' instead of failing the write.
 * - A product import can therefore report success while persisting
 *   product_type = '' (or 0 when a drifted boolean column exists).
 *
 * The importer calls {@see self::incompatibilityReason()} before touching rows,
 * and the repair migration calls {@see self::repair()} to heal existing data.
 */
class ProductTypeSchema
{
    private const TABLE = 'products';

    private const COLUMN = 'product_type';

    /**
     * Canonical values the column must be able to store.
     *
     * @return array<int, string>
     */
    public function values(): array
    {
        return ProductType::getValues();
    }

    /**
     * Raw MySQL column type (for example "enum('simple','variable')").
     * Returns null for non-MySQL drivers or a missing column.
     */
    public function columnType(): ?string
    {
        if (DB::getDriverName() !== 'mysql') {
            return null;
        }

        $column = DB::selectOne(
            'SHOW COLUMNS FROM `' . self::TABLE . '` WHERE Field = ?',
            [self::COLUMN]
        );

        return $column?->Type !== null ? strtolower((string) $column->Type) : null;
    }

    /**
     * Null when the column can store every canonical value, otherwise a
     * human-actionable reason that importers can surface as a system error.
     */
    public function incompatibilityReason(): ?string
    {
        if (DB::getDriverName() !== 'mysql') {
            return null;
        }

        $type = $this->columnType();

        if ($type === null) {
            return __('message.IMPORT.PRODUCT.PRODUCT_TYPE_COLUMN_MISSING');
        }

        if (str_starts_with($type, 'enum(')) {
            return $this->missingEnumValuesReason($type);
        }

        if ($this->isTextType($type)) {
            return null;
        }

        return __('message.IMPORT.PRODUCT.PRODUCT_TYPE_COLUMN_INCOMPATIBLE', [
            'column_type' => $type,
            'canonical_type' => $this->canonicalEnum(),
        ]);
    }

    /**
     * Bring an ENUM column back to the canonical contract and rewrite values
     * that MySQL silently coerced to ''. Unknown ENUM values are never mapped
     * (their semantics are unknowable); the column is left untouched instead.
     *
     * @return bool true when the column is compatible after the attempt
     */
    public function repair(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        $type = $this->columnType();

        if ($type === null) {
            return false;
        }

        if (!str_starts_with($type, 'enum(')) {
            return $this->incompatibilityReason() === null;
        }

        if (!$this->enumCanBeWidened($type)) {
            return false;
        }

        $this->widenEnumToCanonical($type);
        $this->rewriteInvalidValues();

        return $this->incompatibilityReason() === null;
    }

    private function missingEnumValuesReason(string $type): ?string
    {
        $missing = array_values(array_diff($this->values(), $this->enumValues($type)));

        return $missing === []
            ? null
            : __('message.IMPORT.PRODUCT.PRODUCT_TYPE_ENUM_MISSING_VALUES', [
                'missing_values' => implode(', ', $missing),
            ]);
    }

    private function isTextType(string $type): bool
    {
        return preg_match('/^(var)?char\(|^tinytext$|^text$|^mediumtext$|^longtext$/', $type) === 1;
    }

    private function enumCanBeWidened(string $type): bool
    {
        $unmapped = array_values(array_diff($this->enumValues($type), $this->values()));

        if ($unmapped === []) {
            return true;
        }

        Log::warning('products.product_type was not repaired: ENUM contains unmapped values', [
            'values' => $unmapped,
        ]);

        return false;
    }

    private function widenEnumToCanonical(string $type): void
    {
        if (array_diff($this->values(), $this->enumValues($type)) === []) {
            return;
        }

        DB::statement(
            'ALTER TABLE `' . self::TABLE . '` MODIFY `' . self::COLUMN . '` '
            . $this->canonicalEnum() . ' NOT NULL DEFAULT \'' . ProductType::SIMPLE . '\''
        );
    }

    /**
     * The canonical ENUM text, used for ALTER and for importer diagnostics.
     */
    public function canonicalEnum(): string
    {
        $values = implode(',', array_map(
            static fn (string $value): string => "'" . $value . "'",
            $this->values()
        ));

        return "ENUM({$values})";
    }

    private function rewriteInvalidValues(): void
    {
        $this->rewriteProductsWithVariants();
        $this->rewriteRemainingProducts();
    }

    /**
     * Products that own variants are variable by domain definition; mapping
     * them to simple would break cart/checkout eligibility later.
     */
    private function rewriteProductsWithVariants(): void
    {
        $this->invalidValueQuery()
            ->whereExists(static function ($query): void {
                $query->select(DB::raw(1))
                    ->from('product_variants')
                    ->whereColumn('product_variants.product_id', self::TABLE . '.id');
            })
            ->update([self::COLUMN => ProductType::VARIABLE]);
    }

    private function rewriteRemainingProducts(): void
    {
        $this->invalidValueQuery()->update([self::COLUMN => ProductType::SIMPLE]);
    }

    private function invalidValueQuery(): Builder
    {
        return DB::table(self::TABLE)->where(static function ($query): void {
            $query->whereNull(self::COLUMN)
                ->orWhereNotIn(self::COLUMN, ProductType::getValues());
        });
    }

    /**
     * @return array<int, string>
     */
    private function enumValues(string $type): array
    {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $matches);

        return $matches[1] ?? [];
    }
}
