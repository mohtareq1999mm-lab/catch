<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class DigitalAssetUpdateRequest extends FormRequest
{
    public function authorize()
    {
        return true;   // authorization via permission middleware
    }

    public function rules()
    {
        // W6 widened metadata surface. File bytes stay immutable through
        // this endpoint (replacement is the separate explicit operation).
        // `status` is admin-settable only between active/inactive —
        // revoked/expired are system-reserved states.
        return [
            'original_name' => ['sometimes', 'required', 'string', 'max:255'],
            'display_name'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order'    => ['sometimes', 'integer', 'min:0'],
            'status'        => ['sometimes', 'string', 'in:active,inactive'],
            'metadata'      => ['sometimes', 'nullable', 'array', 'max:20'],
            'metadata.*'    => ['string', 'max:1000'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
