<?php

namespace App\Rules;

use App\Models\ProductBarcode;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\Rule;

/**
 * Barcode value uniqueness for the product_barcodes columns.
 *
 * Enforces, per submitted value:
 * 1. Same-row distinctness — the sibling barcode field of the same product
 *    must not carry the identical value (piece != carton).
 * 2. Cross-product uniqueness across BOTH columns — a submitted piece
 *    barcode must not already exist as any other product's piece or carton
 *    barcode (and vice versa), excluding the current product on updates.
 *
 * The current product id is passed from the FormRequest via
 * `$this->route('product')` (matches the UniqueTranslationRule ignore
 * convention already used in ProductUpdateRequest).
 */
class UniqueProductBarcode implements DataAwareRule, Rule
{
    private array $data = [];

    /** Failure mode captured in passes() so message() stays accurate. */
    private ?string $failure = null;

    public function __construct(
        /** 'piece' or 'carton' — determines the sibling field. */
        private readonly string $kind,
        private readonly ?int $ignoreProductId = null,
    ) {
    }

    public function setData($data): static
    {
        $this->data = $data;

        return $this;
    }

    public function passes($attribute, $value): bool
    {
        $this->failure = null;

        if ($value === null || $value === '') {
            return true;
        }

        $siblingField = $this->kind === 'piece' ? 'carton_barcode' : 'piece_barcode';
        if (($this->data[$siblingField] ?? null) === $value) {
            $this->failure = 'same_product';

            return false;
        }

        $taken = ProductBarcode::query()
            ->where(function ($query) use ($value) {
                $query->where('piece_barcode', $value)->orWhere('carton_barcode', $value);
            })
            ->when($this->ignoreProductId !== null, fn ($query) => $query->where('product_id', '!=', $this->ignoreProductId))
            ->exists();

        if ($taken) {
            $this->failure = 'taken';

            return false;
        }

        return true;
    }

    public function message(): string
    {
        return match ($this->failure) {
            'same_product' => 'The :attribute must be different from the product\'s other barcode.',
            'taken' => 'The :attribute is already assigned to another product.',
            default => 'The :attribute is invalid.',
        };
    }
}
