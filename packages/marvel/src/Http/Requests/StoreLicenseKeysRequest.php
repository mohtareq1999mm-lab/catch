<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreLicenseKeysRequest extends FormRequest
{
    public function authorize()
    {
        return true;   // authorization via permission middleware
    }

    public function rules()
    {
        return [
            'keys' => ['required', 'array', 'min:1', 'max:' . config('digital.licenses.max_batch_keys', 500)],
            'keys.*' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    public function withValidator($validator)
    {
        // Intra-batch duplicates (compared trimmed, exact case-sensitive
        // match) are rejected explicitly so one request can never silently
        // double-provision the same secret. Duplicates against the stored
        // pool are skipped with counts at the service layer (see
        // DigitalAssetService::addLicenseKeys).
        $validator->after(function ($validator) {
            $keys = $this->input('keys');

            if (!is_array($keys)) {
                return;
            }

            $seen = [];

            foreach ($keys as $index => $key) {
                if (!is_string($key)) {
                    continue;
                }

                $trimmed = trim($key);

                if ($trimmed === '') {
                    continue;
                }

                if (array_key_exists($trimmed, $seen)) {
                    $validator->errors()->add(
                        "keys.{$index}",
                        __('message.ERROR.DIGITAL_LICENSE_DUPLICATE_KEY', ['index' => $index])
                    );

                    return;
                }

                $seen[$trimmed] = true;
            }
        });
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
