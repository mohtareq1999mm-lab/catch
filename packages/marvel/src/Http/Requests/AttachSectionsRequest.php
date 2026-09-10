<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachSectionsRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // allow an empty array and ensure the field is present
            'sections' => 'present|array',
            'sections.*' => 'integer|exists:sections,id',
        ];
    }

    protected function prepareForValidation()
    {
        if (! $this->has('sections') || $this->input('sections') === null) {
            $this->merge(['sections' => []]);
        }
    }
}