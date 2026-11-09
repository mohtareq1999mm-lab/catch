<?php

namespace App\Rules;

use App\Models\Scopes\FastShippingScope;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;

/**
 * Full-array rule for the ordered `cross_sell_ids` list.
 *
 * Bound to the parent array (NOT to `cross_sell_ids.*`) so `passes()`
 * receives the complete value; element rules (integer/min:1/distinct)
 * stay on the wildcard key (plan rev.3 §5.2).
 *
 * Q-02 semantics (DECIDED):
 * - already-attached soft-deleted targets are permitted (preserved);
 * - newly submitted soft-deleted targets are rejected;
 * - display/channel eligibility is never validated here (D5/D7/Q-01).
 */
final class ValidCrossSellIds implements Rule
{
    private ?int $sourceProductId;
    private bool $requireSource;
    private ?string $failure = null;

    public function __construct(?int $sourceProductId = null, bool $requireSource = false)
    {
        $this->sourceProductId = $sourceProductId;
        $this->requireSource = $requireSource;
    }

    public function passes($attribute, $value): bool
    {
        $this->failure = null;

        if (!is_array($value)) {
            // The 'array' rule owns type reporting.
            return true;
        }

        if (array_values($value) !== $value) {
            $this->failure = 'invalid';
            return false;
        }

        if ($this->requireSource && $this->sourceProductId === null) {
            // Update flow MUST know the source; fail closed (C-03).
            $this->failure = 'invalid';
            return false;
        }

        $ids = [];
        foreach ($value as $raw) {
            if (is_int($raw)) {
                $id = $raw;
            } elseif (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
                $id = (int) $raw;
            } else {
                $this->failure = 'invalid';
                return false;
            }

            if ($id < 1) {
                $this->failure = 'invalid';
                return false;
            }

            $ids[] = $id;
        }

        if ($ids === []) {
            return true;
        }

        if ($this->sourceProductId !== null && in_array($this->sourceProductId, $ids, true)) {
            $this->failure = 'self';
            return false;
        }

        $uniqueIds = array_values(array_unique($ids));
        $products = collect();

        foreach (array_chunk($uniqueIds, 500) as $chunk) {
            $products = $products->merge(
                Product::withTrashed()
                    ->withoutGlobalScope(FastShippingScope::class)
                    ->whereIn('id', $chunk)
                    ->get(['id', 'deleted_at'])
            );
        }

        $products = $products->keyBy(fn ($product) => (int) $product->id);

        foreach ($ids as $id) {
            if (!$products->has($id)) {
                $this->failure = 'missing';
                return false;
            }
        }

        $existingIds = $this->existingIds();

        foreach ($ids as $id) {
            $product = $products->get($id);

            if ($product !== null && $product->trashed() && !in_array($id, $existingIds, true)) {
                $this->failure = 'deleted';
                return false;
            }
        }

        return true;
    }

    private function existingIds(): array
    {
        if ($this->sourceProductId === null) {
            return [];
        }

        return DB::table('product_cross_sells')
            ->where('product_id', $this->sourceProductId)
            ->pluck('related_product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function message(): string
    {
        return match ($this->failure) {
            'self' => __('validation.cross_sell_self'),
            'missing' => __('validation.cross_sell_missing'),
            'deleted' => __('validation.cross_sell_deleted'),
            default => __('validation.cross_sell_invalid'),
        };
    }
}
