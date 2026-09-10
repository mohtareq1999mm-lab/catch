<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;


class ReviewCreateRequest extends FormRequest
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'product_id'            => ['required', 'exists:Marvel\Database\Models\Product,id'],
            'comment'               => ['required', 'string'],
            'rating'                => ['required', 'integer', 'min:1', 'max:5'],
            // 'images'                        => ['sometimes', 'array'],
            // 'images.*'                      => ['required_with:images', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
