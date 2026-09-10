<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\DigitalAsset;
use App\Services\Digital\AssetTypeRegistry;

class ReplaceDigitalAssetRequest extends FormRequest
{
    public function authorize()
    {
        return true;   // authorization via permission middleware
    }

    public function rules()
    {
        // Replacement inherits the W4 byte-truth pipeline: registry-driven
        // extension/MIME/size rules; content inspection happens in the
        // service against the ACTUAL bytes.
        $registry = app(AssetTypeRegistry::class);

        return [
            'file' => ['required', 'file', 'mimes:' . implode(',', $registry->activeExtensions()), 'max:' . $registry->activeMaxKb()],
            // Optional display-name refresh performed alongside replacement.
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $asset = DigitalAsset::query()->where('uuid', (string) $this->route('uuid'))->first();

            if ($asset && $asset->type !== 'FILE') {
                $validator->errors()->add('file', __('message.ERROR.DIGITAL_ASSET_NOT_REPLACEABLE'));
            }
        });
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
