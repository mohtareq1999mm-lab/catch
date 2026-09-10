<?php

namespace App\Http\Resources\Faqs;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FaqResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
         return [
            'id'              => $this->id,
            'faq_title'       => $this->getTranslation('faq_title', app()->getLocale()),
            'faq_description' => $this->getTranslation('faq_description', app()->getLocale()),
            'status'          => (int) $this->status,
            'order'           => (int) $this->order,
        ];
    }
}
