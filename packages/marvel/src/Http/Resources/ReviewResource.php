<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id"                => $this->id,
            "rating"            => $this->rating,
            "comment"           => $this->comment,
            // "user"              => $this->user_id,
            "images"            => $this->getmedia('reviews') ? $this->getmedia('reviews')->map(function ($media) {
                                    return $media->getUrl();
                                }) : [],

            $this->mergeWhen(auth()->user()?->hasPermissionTo('approve-reviews'),[
                'is_approved' => (bool) $this->approved,
            ])
        ];
    }
}