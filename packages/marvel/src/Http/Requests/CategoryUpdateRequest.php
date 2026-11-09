<?php


namespace Marvel\Http\Requests;

use App\Services\General\CategoryHierarchyService;
use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;


class CategoryUpdateRequest extends FormRequest
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
        $id = $this->route('id') ?? $this->route('category') ?? $this->input('id');
        return [
            'name'         => ['sometimes', 'array'],
            'name.*'       => ['sometimes', 'string', UniqueTranslationRule::for('categories')->ignore($id)],
            'image-desktop' => ['sometimes', 'file', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'image-mobile' => ['sometimes', 'file', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'parent_id'    => [
                'nullable',
                'integer',
                'exists:categories,id',
                function ($attribute, $value, $fail) use ($id) {
                    if ($value === null || $id === null) {
                        return;
                    }

                    if (app(CategoryHierarchyService::class)->createsCycle((int) $id, (int) $value)) {
                        $fail(__('message.ERROR.CATEGORY_CIRCULAR_REFERENCE'));
                    }
                },
            ],
           
            'details'      => ['sometimes', 'string', 'min:3', 'max:2500'],
            'active_nav'   => ['sometimes', 'boolean'],
            "products" => "sometimes|array",
            "products.*" => "exists:products,id",
            'status' => ['sometimes', 'in:0,1'],
        ];
    }




    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}