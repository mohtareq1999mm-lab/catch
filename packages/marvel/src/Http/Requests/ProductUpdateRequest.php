<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;

class ProductUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $dimensions = ['height', 'width', 'length', 'weight'];
        foreach ($dimensions as $dim) {
            if ($this->has($dim) && $this->input($dim) !== null) {
                $this->merge([$dim => (string) $this->input($dim)]);
            }
        }
        if ($this->has('variants') && is_array($this->input('variants'))) {
            $variants = $this->input('variants');
            foreach ($variants as $i => $variant) {
                foreach ($dimensions as $dim) {
                    if (isset($variant[$dim]) && $variant[$dim] !== null) {
                        $variants[$i][$dim] = (string) $variant[$dim];
                    }
                }
            }
            $this->merge(['variants' => $variants]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {

        $productType = ProductType::getValues();
        $itemTypes = ItemType::getValues();
        $discountTypes = DiscountType::getValues();

        // Cross-sell source resolution (plan rev.3 §5.4): the route parameter
        // may be a bound Product model OR a numeric id. Fail-closed: when the
        // source id cannot be resolved, ValidCrossSellIds is constructed with
        // requireSource=true so any submitted cross_sell_ids is rejected.
        $routeProduct = $this->route('product');

        $currentProductId = $routeProduct instanceof \Marvel\Database\Models\Product
            ? (int) $routeProduct->getKey()
            : (is_numeric($routeProduct) ? (int) $routeProduct : null);

        return [
            'name'                         => ['sometimes', 'array'],
            'name.*'                       => ['sometimes', 'string', 'max:255', UniqueTranslationRule::for('products')->ignore($this->route('product'))],
            'description'                  => ['sometimes', 'array'],
            'description.*'                => ['sometimes', 'string', 'max:10000'],
            'product_type'                 => ['sometimes', Rule::in($productType)],
            // SKU patches like other sometimes-fields: omitted → kept; provided
            // → saved as-is and must stay unique among OTHER products.
            'sku'                          => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($this->route('product'))],
            'item_type'                    => ['sometimes', 'string', Rule::in($itemTypes)],
            'price'                        => ['sometimes', 'numeric', 'min:0', 'required_if:product_type,' . ProductType::SIMPLE],
            'shop_id'                      => ['sometimes', 'exists:shops,id'],
            'categories'                   => ['sometimes', 'array'],
            'categories.*'                 => ['integer', 'exists:categories,id'],
            'quantity'                     => ['sometimes', 'integer', 'min:1'],
            'images'                       => ['sometimes', 'array'],
            'images.*'                     => ['sometimes', 'image', 'mimes:jpeg,png,jpg,gif,webp', "max:2048"],
            'status'                       => ['sometimes', 'in:0,1'],
            'pieces'                       => ['sometimes', 'integer', 'min:1'],
            'height'                       => ['nullable', 'string'],
            'length'                       => ['nullable', 'string'],
            'width'                        => ['nullable', 'string'],
            'weight'                       => ['nullable', 'string'],
            'in_stock'                     => ['sometimes', 'in:true,false,1,0'],
            'has_discount'                 => ['sometimes', 'in:true,false,1,0'],
            'has_flash_sale'               => ['sometimes', 'in:true,false,1,0'],
            'is_fast_shipping_available'   => ['nullable', 'boolean'],
            'flash_sale_id'                => ['required_if:has_flash_sale,1', 'exists:flash_sales,id'],
            'discount_type'                => ['required_if:has_discount,1', Rule::in($discountTypes)],
            'discount_amount'              => ['required_if:has_discount,1', 'numeric', 'min:1'],
            'discount_status'              => ['required_if:has_discount,1', 'in:true,false,1,0'],
            'start_date'                   => ['sometimes', 'date'],
            'end_date'                     => ['sometimes', 'date', 'after_or_equal:start_date'],
            'brands'                       => ['sometimes', 'array'],
            'brands.*'                     => ['integer', 'exists:brands,id'],
            'tags'                         => ['sometimes', 'array'],
            'tags.*'                       => ['integer', 'exists:tags,id'],
            'banners'                      => ['sometimes', 'array'],
            'banners.*'                    => ['integer', 'exists:banners,id'],
            'sliders'                      => ['sometimes', 'array'],
            'sliders.*'                    => ['integer', 'exists:sliders,id'],

            // Manual cross-selling (ordered directional list). The custom rule
            // receives the FULL array and knows the update source id for
            // self/existing checks. Q-02: already-attached soft-deleted targets
            // are allowed to remain; newly submitted ones are rejected.
            // Display/channel eligibility is never validated here (D5/D7/Q-01).
            'cross_sell_ids'               => ['sometimes', 'array', new \App\Rules\ValidCrossSellIds($currentProductId, true)],
            'cross_sell_ids.*'             => ['integer', 'min:1', 'distinct'],

            'tax_enabled'                  => ['sometimes', 'boolean'],
            'tax_rate'                     => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Barcode attributes (product_barcodes table). Fields are
            // independently nullable; values must be unique across BOTH
            // columns and the current product is excluded on updates
            // (apiResource products/{product} → route('product')).
            'piece_barcode'                => ['nullable', 'string', 'max:64', new \App\Rules\UniqueProductBarcode('piece', $this->route('product'))],
            'carton_barcode'               => ['nullable', 'string', 'max:64', new \App\Rules\UniqueProductBarcode('carton', $this->route('product'))],
            'pieces_per_carton'            => ['nullable', 'integer', 'min:1'],

            // WMS placements: multiple locations with quantities
            'locations'                    => ['sometimes', 'array'],
            'locations.*.location_id'      => ['required_with:locations', 'integer', 'exists:locations,id'],
            'locations.*.quantity'         => ['required_with:locations', 'numeric', 'min:0'],
            'locations.*.warehouse_id'     => ['nullable', 'integer', 'exists:warehouses,id'],

            // variants
            'variants'                     => ['sometimes', 'array'],
            'variants.*.id'                => ['sometimes', 'exists:product_variants,id'], // مهم علشان نعرف أي Variant يتعدل
            'variants.*.price'             => ['sometimes', 'numeric', 'min:0'],
            'variants.*.sale_price'        => ['sometimes', 'numeric', 'min:0'],
            'variants.*.quantity'          => ['sometimes', 'integer', 'min:0'],
            'variants.*.weight'            => ['sometimes', 'string'],
            'variants.*.length'            => ['sometimes', 'string'],
            'variants.*.width'             => ['sometimes', 'string'],
            'variants.*.height'            => ['sometimes', 'string'],
            'variants.*.attribute_values'  => ['sometimes', 'array'],
            'variants.*.attribute_values.*' => ['integer', 'exists:attribute_values,id'],
            'variants.*.locations'                    => ['sometimes', 'array'],
            'variants.*.locations.*.location_id'      => ['required_with:variants.*.locations', 'integer', 'exists:locations,id'],
            'variants.*.locations.*.quantity'         => ['required_with:variants.*.locations', 'numeric', 'min:0'],
            'variants.*.locations.*.warehouse_id'      => ['nullable', 'integer', 'exists:warehouses,id'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }

    public function attributes(): array
    {
        return [
            'name.*' => __('validation.attributes.name'),
            'description.*' => __('validation.attributes.description'),
            'variants.*.price' => __('validation.attributes.price'),
            'variants.*.quantity' => __('validation.attributes.quantity'),
        ];
    }
}