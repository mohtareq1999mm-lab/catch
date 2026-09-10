<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,ods',
                'max:20480',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => __('message.IMPORT.VALIDATION.FILE_REQUIRED'),
            'file.mimes' => __('message.IMPORT.VALIDATION.FILE_MIMES'),
            'file.max' => __('message.IMPORT.VALIDATION.FILE_MAX'),
        ];
    }
}
