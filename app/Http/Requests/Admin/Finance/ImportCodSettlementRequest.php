<?php

namespace App\Http\Requests\Admin\Finance;

use App\Enums\SettlementMode;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * #240: finance-only manual statement file import input.
 *
 * Extension and size limits mirror `config('settlement.imports.*')`; the
 * service re-validates bytes, MIME, content, and hash idempotency, so these
 * rules only reject obviously invalid uploads early. Authorization is
 * enforced by the route/controller permission middleware.
 */
class ImportCodSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBytes = (int) config('settlement.imports.max_bytes', 2097152);
        // Laravel `max` counts uploaded files in kilobytes; ceil keeps the
        // validator from being stricter than the service's exact byte limit.
        $maxKilobytes = max(1, (int) ceil($maxBytes / 1024));
        $allowedExtensions = array_map(
            static fn (string $extension): string => strtolower(trim($extension)),
            (array) config('settlement.imports.allowed_extensions', ['csv'])
        );

        return [
            // Laravel 10 has no `extensions` rule; the upload extension is
            // validated the same way the service does it.
            'file' => [
                'required',
                'file',
                'max:' . $maxKilobytes,
                function (string $attribute, mixed $value, Closure $fail) use ($allowedExtensions): void {
                    if (!$value instanceof UploadedFile) {
                        return;
                    }

                    $extension = strtolower((string) $value->getClientOriginalExtension());
                    if (!in_array($extension, $allowedExtensions, true)) {
                        $fail(__('cod_settlement.invalid_file'));
                    }
                },
            ],
            'statement_key' => ['required', 'string', 'max:191'],
            'provider_code' => ['required', 'string', 'max:32'],
            'mode' => ['required', 'string', Rule::in(array_map(
                fn (SettlementMode $mode): string => $mode->value,
                SettlementMode::cases()
            ))],
            'account_code' => ['required', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        $maxBytes = (int) config('settlement.imports.max_bytes', 2097152);

        return [
            'file.required' => __('cod_settlement.invalid_file'),
            'file.file' => __('cod_settlement.invalid_file'),
            'file.uploaded' => __('cod_settlement.invalid_file'),
            'file.extensions' => __('cod_settlement.invalid_file'),
            'file.max' => __('cod_settlement.file_too_large', ['max' => $maxBytes]),
            'statement_key.required' => __('cod_settlement.invalid_payload', ['field' => 'statement_key']),
            'statement_key.string' => __('cod_settlement.invalid_payload', ['field' => 'statement_key']),
            'statement_key.max' => __('cod_settlement.invalid_payload', ['field' => 'statement_key']),
            'provider_code.required' => __('cod_settlement.invalid_payload', ['field' => 'provider_code']),
            'provider_code.string' => __('cod_settlement.invalid_payload', ['field' => 'provider_code']),
            'provider_code.max' => __('cod_settlement.invalid_payload', ['field' => 'provider_code']),
            'mode.required' => __('cod_settlement.invalid_payload', ['field' => 'mode']),
            'mode.string' => __('cod_settlement.invalid_payload', ['field' => 'mode']),
            'mode.in' => __('cod_settlement.invalid_payload', ['field' => 'mode']),
            'account_code.required' => __('cod_settlement.invalid_payload', ['field' => 'account_code']),
            'account_code.string' => __('cod_settlement.invalid_payload', ['field' => 'account_code']),
            'account_code.max' => __('cod_settlement.invalid_payload', ['field' => 'account_code']),
        ];
    }
}
