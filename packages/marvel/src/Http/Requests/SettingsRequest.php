<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class SettingsRequest extends FormRequest
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
            "site_name" => ['sometimes', 'array'],
            "site_name.*" => ['sometimes', 'string', 'min:3', "max:200"],
            "site_desc" => ['sometimes', 'array'],
            "site_desc.*" => ['sometimes', 'string', 'min:3', "max:2000"],
            "meta_desc" => ['sometimes', 'array'],
            "meta_desc.*" => ['sometimes', 'string', 'min:3', "max:2000"],
            "site_copy_right" => ['sometimes', 'array'],
            "site_copy_right.*" => ['sometimes', 'string', 'min:3', "max:200"],
            "logo" => ['sometimes', "image", "mimes:jpeg,png,jpg,gif,svg", "max:2048"],
            "footer_logo" => ['sometimes', "image", "mimes:jpeg,png,jpg,gif,svg", "max:2048"],
            "favicon" => ['sometimes', "image", "mimes:jpeg,png,jpg,gif,svg", "max:2048"],
            "site_email" => ['sometimes', 'email'],
            "email_support" => ['sometimes', 'email'],
            "facebook" => ['sometimes', 'url'],
            "instagram" => ['sometimes', 'url'],
            "linkedin" => ['sometimes', 'url'],
            "promotion_video_url" => ['sometimes', 'url'],
            'youtube' => ['sometimes', 'url'],
            'tiktok' => ['sometimes', 'url'],
            'snapchat' => ['sometimes', 'url'],
            'phone' => ['sometimes', 'string'],
            'fast_shipping_page_publish' => ['sometimes', 'in:0,1'],
            'minimum_order_amount' => ['sometimes', 'numeric', 'min:0'],
            'currency_selection_enabled' => ['sometimes', 'boolean'],
            'order_tax_enabled' => ['sometimes', 'boolean'],
            'order_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'options' => ['sometimes', 'array'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
