<?php

namespace App\Http\Resources\SiteReview;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteReviewResource extends JsonResource
{
    /**
     * Public website review resource. Only moderation-safe fields are exposed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'title' => $this->title,
            'comment' => $this->comment,
            'customer' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
