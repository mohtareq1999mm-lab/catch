<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;


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
            "site_name" => ['nullable', 'array'],
            "site_name.*" => ['nullable', 'string', 'min:3', "max:200"],
            "site_desc" => ['nullable', 'array'],
            "site_desc.*" => ['nullable', 'string', 'min:3', "max:2000"],
            "meta_desc" => ['nullable', 'array'],
            "meta_desc.*" => ['nullable', 'string', 'min:3', "max:2000"],
            "site_copy_right" => ['nullable', 'array'],
            "site_copy_right.*" => ['nullable', 'string', 'min:3', "max:200"],
            "logo" => ['nullable', "image", "mimes:jpeg,png,jpg,gif,svg,webp", "max:2048"],
            "footer_logo" => ['nullable', "image", "mimes:jpeg,png,jpg,gif,svg,webp", "max:2048"],
            "favicon" => ['nullable', "image", "mimes:jpeg,png,jpg,gif,svg,webp,ico", "max:2048"],
            "site_email" => ['nullable', 'email'],
            "email_support" => ['nullable', 'email'],
            "facebook" => ['nullable', 'url'],
            "instagram" => ['nullable', 'url'],
            "linkedin" => ['nullable', 'url'],
            "promotion_video_url" => ['nullable', 'url'],
            'youtube' => ['nullable', 'url'],
            'tiktok' => ['nullable', 'url'],
            'snapchat' => ['nullable', 'url'],
            'phone' => ['nullable', 'string'],
            'fast_shipping_page_publish' => ['nullable', 'in:0,1'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'currency_selection_enabled' => ['nullable', 'boolean'],
            'order_tax_enabled' => ['nullable', 'boolean'],
            'order_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'options' => ['nullable', 'array'],
            // Per-gateway toggles through the Site Settings endpoint:
            // {payment_gateways: {code: {enabled: bool}}}. Mirrors the
            // shape the settings GET responses expose; `available` is a
            // derived display verdict and is NOT writable. Unknown gateway
            // codes are rejected by the controller so the error can name
            // the offending code.
            'payment_gateways' => ['nullable', 'array'],
            'payment_gateways.*' => ['array'],
            'payment_gateways.*.enabled' => ['required', 'boolean'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
