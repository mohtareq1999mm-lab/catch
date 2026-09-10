<?php


namespace Marvel\Http\Requests;

use App\Services\General\CategoryHierarchyService;
use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;


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
            'image-desktop' => ['sometimes', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'image-mobile' => ['sometimes', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'parent_id'    => [
                'nullable',
                'integer',
                'exists:categories,id',
                function ($attribute, $value, $fail) use ($id) {
                    if ($value === null || $id === null) {
                        return;
                    }

                    if (app(CategoryHierarchyService::class)->createsCycle((int) $id, (int) $value)) {
                        $fail('The selected parent category creates a circular reference.');
                    }
                },
            ],
           
            'details'      => ['sometimes', 'string', 'min:3', 'max:2500'],
            "products" => "sometimes|array",
            "products.*" => "exists:products,id",
            'status' => ['sometimes', 'in:0,1'],
        ];
    }




    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}