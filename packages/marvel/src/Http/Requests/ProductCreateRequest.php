<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ItemType;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;

class ProductCreateRequest extends FormRequest
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
        $productStatus = ProductStatus::getValues();

        $productType = ProductType::getValues();

        $itemTypes = ItemType::getValues();

        $discountTypes = DiscountType::getValues();

        return [
            'name'                         => ['required', 'array'],
            'name.*'                       => ['required', 'string', 'max:255', UniqueTranslationRule::for('products')],
            'description'                  => ['required', 'array'],
            'description.*'                => ['required', 'string', 'max:10000'],
            'price'                        => ['sometimes', 'numeric', 'min:0', 'required_if:product_type,' . ProductType::SIMPLE],
            'product_type'                 => ['required', Rule::in($productType)],
            'item_type'                    => ['sometimes', 'string', Rule::in($itemTypes)],
            'type_id'                      => ['sometimes', 'integer', 'exists:types,id'],
            'categories'                   => ['required', 'array'],
            'categories.*'                 => ['integer', 'exists:categories,id'],
            'quantity'                     => ['sometimes', 'integer', 'min:1'],
            'images'                        => ['required', 'array'],
            'images.*'                      => ['required', 'file', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'pieces'                       => ['sometimes', 'integer', 'min:1'],
            'status'                       => ['sometimes', Rule::in($productStatus)],
            'height'                       => ['nullable', 'string'],
            'length'                       => ['nullable', 'string'],
            'width'                        => ['nullable', 'string'],
            'weight'                       => ['nullable', 'string'],
            'in_stock'                     => ["required", 'in:1,0'],
            'has_discount'                 => ["required", 'in:true,false,1,0'],
            'has_flash_sale'               => ["required", 'in:true,false,1,0'],
            'is_fast_shipping_available'   => ['nullable', 'boolean'],
            'flash_sale_id'                => ['required_if:has_flash_sale,1', 'exists:flash_sales,id'],
            'discount_type'                => ['required_if:has_discount,1', Rule::in($discountTypes)],
            'discount_amount'              => ['required_if:has_discount,1', 'numeric', 'min:1'],
            'discount_status'              => ['required_if:has_discount,1', 'in:1,0'],
            'start_date'                   => ['sometimes', 'date'],
            'end_date'                     => ['sometimes', 'date', 'after_or_equal:start_date'],
            'brands'                       => ['sometimes', 'array'],
            'brands.*'                     => ['integer', 'exists:brands,id'],
            'tags'                         => ['nullable', 'array'],
            'tags.*'                       => ['integer', 'exists:tags,id'],
            'banners'                      => ['sometimes', 'array'],
            'banners.*'                    => ['integer', 'exists:banners,id'],
            'sliders'                      => ['sometimes', 'array'],
            'sliders.*'                    => ['integer', 'exists:sliders,id'],
            'tax_enabled'                  => ['sometimes', 'boolean'],
            'tax_rate'                     => ['nullable', 'numeric', 'min:0', 'max:100'],

            // variants (for variable products)
            'variants'                     => ['sometimes', 'array'],
            'variants.*.price'             => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.quantity'          => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.weight'            => ['sometimes', 'string'],
            'variants.*.length'            => ['sometimes', 'string'],
            'variants.*.width'             => ['sometimes', 'string'],
            'variants.*.height'            => ['sometimes', 'string'],
            'variants.*.attribute_values'  => ['required_with:variants', 'array'],
            'variants.*.attribute_values.*' => ['integer', 'exists:attribute_values,id'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
