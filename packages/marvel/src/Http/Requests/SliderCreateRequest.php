<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class   SliderCreateRequest extends FormRequest
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
            'title.*' => 'required|array',
            'title.en'=> ['required', 'string', UniqueTranslationRule::for('sliders', 'title')],
            'title.ar'=> ['required', 'string', UniqueTranslationRule::for('sliders', 'title')],
            "image_desktop"=> "required | image | mimes:jpeg,png,jpg,gif | max:2048",
            "image_mobile"=> "required | image | mimes:jpeg,png,jpg,gif | max:2048",
            "status"=> "sometimes | in:1,0",
            "products" => "sometimes|array",
            "products.*" => "exists:products,id",
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}