<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Database\Models\Product;

class WishlistCreateRequest extends FormRequest
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
            'product_id'            => ['required', 'exists:Marvel\Database\Models\Product,id'],
            'product_variant_id' => [
                Rule::requiredIf(function () {
                    $product = Product::find(request('product_id'));

                    return $product && $product->variations()->exists();
                }),
                'integer',
                'exists:product_variants,id',
            ],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
