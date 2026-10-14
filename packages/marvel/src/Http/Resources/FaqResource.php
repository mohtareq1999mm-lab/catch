<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class FaqResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray(Request $request)
    {
        return [
            'id'              => $this->id,
            'faq_title'       => request()->routeIs('faqs.index') ? $this->getTranslation('faq_title', app()->getLocale()) : [
                'ar' => $this->getTranslation('faq_title', 'ar'),
                'en' => $this->getTranslation('faq_title', 'en'),
            ],
            'faq_description' => request()->routeIs('faqs.index') ? $this->getTranslation('faq_description', app()->getLocale()) : [
                'ar' => $this->getTranslation('faq_description', 'ar'),
                'en' => $this->getTranslation('faq_description', 'en'),
            ],
            'status'          => (int) $this->status,
            'order'           => (int) $this->order,
        ];
    }
}