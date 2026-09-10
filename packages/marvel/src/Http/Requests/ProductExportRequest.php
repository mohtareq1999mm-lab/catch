<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'sometimes|boolean',
            'product_type' => 'sometimes|string|in:simple,variable',
            'item_type' => 'sometimes|string|in:PHYSICAL,DIGITAL',
            'category_id' => 'sometimes|integer|exists:categories,id',
            'brand_id' => 'sometimes|integer|exists:brands,id',
        ];
    }
}
