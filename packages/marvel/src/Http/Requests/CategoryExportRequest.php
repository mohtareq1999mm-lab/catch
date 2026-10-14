<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoryExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}