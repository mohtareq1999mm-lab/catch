<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Marvel\Models\Section;

class UpdateSectionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'title' => 'sometimes|array',
            'title.*' => ['sometimes', 'string', 'max:50'],
            'order' => 'sometimes|integer',
            'is_active' => 'sometimes|in:0,1',
            'title_visible' => 'sometimes|in:0,1',
            'setting' => 'nullable|array',
            'setting.front' => 'nullable|array',
            'setting.back' => 'nullable|array',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $withProduct = $this->input('with_product');

            if (is_null($withProduct)) {
                $section = $this->route('section');
                if ($section) {
                    $withProduct = $section->with_product ?? false;
                }
            }

            if ($withProduct) {
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
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
