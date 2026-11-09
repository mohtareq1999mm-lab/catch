<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreSectionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('type') === 'hero') {
            app(\App\Services\General\SectionTypeService::class)->ensureHeroTypeExists();
            if (!$this->has('title')) {
                $this->merge([
                    'title' => ['en' => 'Hero', 'ar' => 'هيرو'],
                ]);
            }
        }

        foreach (['is_active', 'title_visible'] as $boolField) {
            if ($this->has($boolField) && is_bool($this->input($boolField))) {
                $this->merge([$boolField => $this->input($boolField) ? 1 : 0]);
            }
        }
    }

    public function rules()
    {
        return [
            'type' => 'required|string|max:100|exists:section_types,type',
            'title' => 'required|array',
            'title.*' => ['required', 'string', 'max:50'],
            'is_active' => 'nullable|in:0,1',
            'title_visible' => 'nullable|in:0,1',
            'order' => 'nullable|integer',
            'setting' => 'nullable|array',
            'setting.front' => 'nullable|array',
            'setting.back' => 'nullable|array',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->input('with_product')) {
                $setting = $this->input('setting', []);
                $back = $setting['back'] ?? [];
                $allowedKeys = ['slug'];
                $extraKeys = array_diff(array_keys($back), $allowedKeys);

                if (!empty($extraKeys)) {
                    $validator->errors()->add('setting.back', 'When with_product is true, only "slug" is allowed in setting.back.');
                }
            }
        });
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
