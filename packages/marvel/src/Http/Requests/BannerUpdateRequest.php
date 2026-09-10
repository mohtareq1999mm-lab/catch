<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class BannerUpdateRequest extends FormRequest
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
        $id = $this->route("banner");
        return [
            "title"=> "sometimes | array",
            "title.en"=> "sometimes | string | max:255 | min:3 | ".UniqueTranslationRule::for('banners','title')->ignore($id),
            "title.ar"=> "sometimes | string | max:255 | min:3 | ".UniqueTranslationRule::for('banners','title')->ignore($id),
           "description"=> "sometimes | array",
           "description.en"=> "nullable|string|max:500|min:10|".UniqueTranslationRule::for('banners','description')->ignore($id),
           "description.ar"=> "nullable|string|max:500|min:10|".UniqueTranslationRule::for('banners','description')->ignore($id),
           "image_desktop"=> "sometimes | image | mimes:jpeg,png,jpg,gif | max:2048",
           "image_mobile"=> "sometimes | image | mimes:jpeg,png,jpg,gif | max:2048",
           "status"=> "sometimes | in:0,1",
           "products"=> "sometimes|array",
           "products.*"=> "exists:products,id",
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}