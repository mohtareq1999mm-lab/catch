<?php


namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;


class CategoryCreateRequest extends FormRequest
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

    protected function prepareForValidation(): void
    {
        if ($this->has('active_nav')) {
            $val = $this->input('active_nav');
            if (is_string($val)) {
                $lower = strtolower($val);
                if (in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
                    $this->merge(['active_nav' => true]);
                } elseif (in_array($lower, ['0', 'false', 'off', 'no'], true)) {
                    $this->merge(['active_nav' => false]);
                }
            } elseif (is_int($val)) {
                $this->merge(['active_nav' => $val === 1]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name' => ['required', 'array'],
            'name.*' => ['required', 'string', UniqueTranslationRule::for('categories', 'name')],
            'image-desktop' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'image-mobile' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'details'      => ['sometimes', 'string', 'min:3', 'max:2500'],
            'active_nav'   => ['sometimes', 'boolean'],
            "products" => "sometimes|array",
            "products.*" => "exists:products,id",
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
