<?php

namespace App\Http\Resources\SiteReview;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSiteReviewResource extends JsonResource
{
    /**
     * Admin website review resource. Exposes moderation information.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'customer' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'rating' => $this->rating,
            'title' => $this->title,
            'comment' => $this->comment,
            'status' => $this->status,
            'moderator' => $this->whenLoaded('moderator') && $this->moderator
                ? [
                    'id' => $this->moderator->id,
                    'name' => $this->moderator->name,
                ]
                : null,
            'moderated_at' => $this->moderated_at,
            'created_at' => $this->created_at,
        ];
    }
}
