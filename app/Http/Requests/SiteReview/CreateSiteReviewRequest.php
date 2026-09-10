<?php

namespace App\Http\Requests\SiteReview;

use Illuminate\Foundation\Http\FormRequest;

class CreateSiteReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:191'],
            'comment' => ['required', 'string', 'max:2000'],
        ];
    }
}
