<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSectionTypeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'type' => 'sometimes|string|max:100|unique:section_types,type,' . $this->route('section_type')->id,
        ];
    }
}
