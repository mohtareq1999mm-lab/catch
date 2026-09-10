<?php

namespace App\Services\SiteReview;

use App\Enums\SiteReviewStatus;
use App\Models\SiteReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\User;

class SiteReviewService
{
    /**
     * Create a new website review. Every new review is created as pending
     * and is never exposed publicly until an admin approves it.
     */
    public function createReview(User $customer, array $data): SiteReview
    {
        return SiteReview::create([
            'user_id' => $customer->getKey(),
            'rating' => (int) $data['rating'],
            'title' => $data['title'] ?? null,
            'comment' => $data['comment'],
            'status' => SiteReviewStatus::PENDING,
            'moderated_by' => null,
            'moderated_at' => null,
        ]);
    }

    /**
     * Public list — only approved reviews, never pending/rejected.
     */
    public function getApprovedReviews(): Collection
    {
        return SiteReview::query()
            ->with('user')
            ->where('status', SiteReviewStatus::APPROVED)
            ->latest()
            ->get();
    }

    /**
     * Admin list — supports filtering by moderation status.
     * User and moderator are eager loaded to prevent N+1 queries.
     */
    public function getAllReviews(?string $status, int $limit = 15): LengthAwarePaginator
    {
        $query = SiteReview::query()
            ->with(['user', 'moderator'])
            ->latest();

        if ($status !== null && $status !== 'all' && in_array($status, SiteReviewStatus::values(), true)) {
            $query->where('status', $status);
        }

        return $query->paginate($limit);
    }

    /**
     * Admin detail view with user and moderator eager loaded.
     */
    public function findReview(int $id): ?SiteReview
    {
        return SiteReview::query()
            ->with(['user', 'moderator'])
            ->find($id);
    }

    /**
     * Approve a pending review. Only the pending -> approved transition is
     * supported. Returns null when the review is missing or already moderated.
     */
    public function approveReview(int $id, User $admin): ?SiteReview
    {
        return $this->moderate($id, $admin, SiteReviewStatus::APPROVED);
    }

    /**
     * Reject a pending review. Only the pending -> rejected transition is
     * supported. Returns null when the review is missing or already moderated.
     */
    public function rejectReview(int $id, User $admin): ?SiteReview
    {
        return $this->moderate($id, $admin, SiteReviewStatus::REJECTED);
    }

    private function moderate(int $id, User $admin, SiteReviewStatus $targetStatus): ?SiteReview
    {
        return DB::transaction(function () use ($id, $admin, $targetStatus) {
            $review = SiteReview::query()->find($id);

            if (!$review || $review->status !== SiteReviewStatus::PENDING) {
                return null;
            }

            $review->update([
                'status' => $targetStatus,
                'moderated_by' => $admin->getKey(),
                'moderated_at' => now(),
            ]);

            return $review->load(['user', 'moderator']);
        });
    }
}
