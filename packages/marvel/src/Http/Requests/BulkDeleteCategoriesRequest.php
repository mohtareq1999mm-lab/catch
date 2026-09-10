<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => [
                'required',
                'array',
                'min:1',
            ],
            'ids.*' => [
                'integer',
                'min:1',
                'distinct',
                'exists:categories,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => __('message.MESSAGE.CATEGORY_BULK_DELETE_IDS_REQUIRED'),
            'ids.array' => __('message.MESSAGE.CATEGORY_BULK_DELETE_IDS_REQUIRED'),
            'ids.min' => __('message.MESSAGE.CATEGORY_BULK_DELETE_IDS_REQUIRED'),
            'ids.*.integer' => __('message.MESSAGE.CATEGORY_BULK_DELETE_IDS_REQUIRED'),
            'ids.*.min' => __('message.MESSAGE.CATEGORY_BULK_DELETE_IDS_REQUIRED'),
            'ids.*.distinct' => __('message.MESSAGE.CATEGORY_BULK_DELETE_IDS_REQUIRED'),
            'ids.*.exists' => __('message.MESSAGE.CATEGORY_BULK_DELETE_IDS_REQUIRED'),
        ];
    }
}