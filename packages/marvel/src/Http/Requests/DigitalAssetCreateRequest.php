<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use App\Services\Digital\AssetTypeRegistry;
use App\Enums\DigitalAssetType;

class DigitalAssetCreateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        // Validation metadata comes exclusively from the Asset Type Registry
        // (active upload surface + creatable delivery types).
        $registry = app(AssetTypeRegistry::class);
        $creatable = $registry->creatableTypes();
        $type = $this->input('type');

        $isFile = !in_array($type, [DigitalAssetType::URL->value, DigitalAssetType::LICENSE->value, DigitalAssetType::ACCESS->value], true);

        return [
            'file' => array_merge(
                $isFile ? ['required'] : ['prohibited'],
                ['file', 'mimes:' . implode(',', $registry->activeExtensions()), 'max:' . $registry->activeMaxKb()]
            ),
            'type' => ['sometimes', 'string', Rule::in($creatable)],
            'original_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],

            // W5 — external URL assets.
            'external_url' => [
                Rule::requiredIf($type === DigitalAssetType::URL->value),
                'nullable',
                'string',
                'max:' . config('digital.external_urls.max_length', 2048),
            ],

            // W5 — ACCESS credentials (LICENSE pools are provisioned via
            // the dedicated key-import endpoint, not at creation).
            'secret' => [
                Rule::requiredIf($type === DigitalAssetType::ACCESS->value),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
