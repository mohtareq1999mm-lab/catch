<?php

namespace App\Http\Requests;

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
            'shop_id' => 'sometimes|integer|exists:shops,id',
            'status' => 'sometimes|boolean',
            'product_type' => 'sometimes|string|in:simple,variable',
            'category_id' => 'sometimes|integer|exists:categories,id',
            'brand_id' => 'sometimes|integer|exists:brands,id',
        ];
    }
}
