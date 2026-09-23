<?php

namespace Marvel\Database\Repositories;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use App\Events\CouponAssigned;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Exceptions\MarvelBadRequestException;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class CouponAssignmentRepository extends BaseRepository
{
    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
        }
    }

    public function model()
    {
        return CouponAssignment::class;
    }

    public function modelQuery()
    {
        return CouponAssignment::query();
    }

    public function listByCoupon(int $couponId, int $perPage = 15): LengthAwarePaginator
    {
        return CouponAssignment::where('coupon_id', $couponId)
            ->with('user')
            ->orderBy('assigned_at', 'desc')
            ->paginate($perPage);
    }

    public function findAssignment(int $couponId, int $assignmentId): Model
    {
        $assignment = CouponAssignment::where('coupon_id', $couponId)
            ->where('id', $assignmentId)
            ->with('user')
            ->first();

        if (!$assignment) {
            throw new ModelNotFoundException('Assignment not found for this coupon.');
        }

        return $assignment;
    }

    public function assignCoupon(int $couponId, array $data): Model
    {
        $coupon = Coupon::findOrFail($couponId);

        $exists = CouponAssignment::where('coupon_id', $couponId)
            ->where('user_id', $data['user_id'])
            ->exists();

        if ($exists) {
            throw new MarvelBadRequestException('COUPON_ALREADY_ASSIGNED_TO_USER');
        }

        // F-08/N-03: concurrent inserts can both pass the exists() check.
        // unique(coupon_id,user_id) is the arbiter; map its violation to a
        // controlled 409 business response instead of a raw DB exception.
        // Lock ordering (F-13): Coupon → CouponAssignment.
        try {
            $assignment = DB::transaction(function () use ($couponId, $data) {
                $assignment = CouponAssignment::create([
                    'coupon_id' => $couponId,
                    'user_id' => $data['user_id'],
                    'max_uses' => $data['max_uses'],
                    'expires_at' => $data['expires_at'] ?? null,
                ]);

                return $assignment->fresh();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new MarvelBadRequestException('COUPON_ALREADY_ASSIGNED_TO_USER');
            }
            throw $e;
        }

        event(new CouponAssigned($assignment));

        // Assignment population drives discovery classification and quota
        // display: retire customer listing caches.
        \App\Services\Coupon\Discovery\CouponDiscoveryCache::invalidate();

        return $assignment;
    }

    public function updateAssignment(int $couponId, int $assignmentId, array $data): Model
    {
        $assignment = $this->findAssignment($couponId, $assignmentId);

        if (isset($data['max_uses']) && (int) $data['max_uses'] < $assignment->used) {
            throw new MarvelBadRequestException('MAX_USES_BELOW_USED_COUNT');
        }

        $allowed = [];

        if (array_key_exists('max_uses', $data)) {
            $allowed['max_uses'] = (int) $data['max_uses'];
        }

        if (array_key_exists('expires_at', $data)) {
            $allowed['expires_at'] = $data['expires_at'];
        }

        if (!empty($allowed)) {
            $assignment->update($allowed);
            \App\Services\Coupon\Discovery\CouponDiscoveryCache::invalidate();
        }

        return $assignment->fresh()->load('user');
    }

    public function removeAssignment(int $couponId, int $assignmentId): void
    {
        DB::transaction(function () use ($couponId, $assignmentId) {
            $assignment = CouponAssignment::where('coupon_id', $couponId)
                ->where('id', $assignmentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($assignment->used > 0) {
                throw new MarvelBadRequestException('CANNOT_DELETE_ASSIGNMENT_WITH_USAGE');
            }

            $assignment->delete();

            \App\Services\Coupon\Discovery\CouponDiscoveryCache::invalidate();
        });
    }

    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $sqlState = $e->getCode();
        $message = strtolower($e->getMessage());

        return $sqlState === '23000'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint');
    }
}
